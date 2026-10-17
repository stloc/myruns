<?php

declare(strict_types=1);

namespace App\Import;

use App\Elevation\ElevationCorrector;
use App\Entity\Activity;
use App\Enum\Sport;
use App\Import\Track\ParsedTrack;
use App\Import\Track\RouteSimplifier;
use App\Import\Track\SplitCalculator;
use App\Repository\ActivityRepository;
use App\Repository\UpsertOutcome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Importe les activités depuis :
 *  - l'archive ZIP de l'export de compte Strava (ou le dossier décompressé) : activities.csv + traces ;
 *  - un dossier de fichiers FIT/GPX/TCX alimenté par Tapiriik, une extension navigateur, etc.
 *
 * Le CSV fait foi pour le résumé ; les traces apportent les temps de passage et la FC tenue,
 * rattachés à la ligne du CSV par le nom du fichier (colonne « Nom du fichier »).
 * L'import est idempotent : on peut le relancer régulièrement (cron) sur le même dossier.
 */
final class ActivityImporter
{
    private const TRACK_PATTERNS = ['*.fit', '*.gpx', '*.tcx', '*.fit.gz', '*.gpx.gz', '*.tcx.gz'];

    /** @var array<string, Activity> activités du CSV indexées par fichier de trace, pour l'import en cours */
    private array $byTrackFile = [];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly StravaCsvParser $csvParser,
        private readonly TrackFileParser $trackParser,
        private readonly SplitCalculator $splitCalculator,
        private readonly ElevationCorrector $elevationCorrector,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly RouteSimplifier $routeSimplifier = new RouteSimplifier(),
    ) {
    }

    /**
     * @param string $path chemin absolu, ou relatif au dossier du projet (seul dossier visible sous DDEV)
     */
    public function import(string $path): ImportResult
    {
        $result = new ImportResult();
        if ('' !== $path && !Path::isAbsolute($path)) {
            $path = Path::join($this->projectDir, $path);
        }

        if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
            $this->importZip($path, $result);
        } elseif (is_dir($path)) {
            $this->importDirectory($path, $result);
        } elseif (is_file($path) && TrackFileParser::supports($path)) {
            $this->byTrackFile = $this->activities->indexByTrackFile();
            $this->importTrack(basename($path), (string) file_get_contents($path), $result);
        } else {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est ni une archive ZIP Strava, ni un dossier, ni un fichier FIT/GPX/TCX.', $path));
        }

        $this->activities->flush();

        return $result;
    }

    private function importZip(string $path, ImportResult $result): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new \RuntimeException(\sprintf('Impossible d\'ouvrir l\'archive « %s ».', $path));
        }

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if ('activities.csv' === basename($name)) {
                    $stream = $zip->getStream($name);
                    if (false !== $stream) {
                        $this->importCsv($stream, $result);
                        fclose($stream);
                    }
                }
            }

            $this->byTrackFile = $this->activities->indexByTrackFile();
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (TrackFileParser::supports($name)) {
                    $this->importTrack($name, (string) $zip->getFromIndex($i), $result);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function importDirectory(string $path, ImportResult $result): void
    {
        foreach ((new Finder())->files()->in($path)->name('activities.csv') as $csv) {
            $stream = fopen($csv->getPathname(), 'r');
            if (false !== $stream) {
                $this->importCsv($stream, $result);
                fclose($stream);
            }
        }

        foreach ((new Finder())->files()->in($path)->depth(0)->name('*.zip') as $zip) {
            $this->importZip($zip->getPathname(), $result);
        }

        $this->byTrackFile = $this->activities->indexByTrackFile();
        foreach ((new Finder())->files()->in($path)->name(self::TRACK_PATTERNS) as $track) {
            $this->importTrack($track->getFilename(), $track->getContents(), $result);
        }
    }

    /**
     * @param resource $stream
     */
    private function importCsv($stream, ImportResult $result): void
    {
        foreach ($this->csvParser->parse($stream) as $activity) {
            $this->count($this->activities->upsert($activity)->outcome, $result);
        }
    }

    private function importTrack(string $filename, string $contents, ImportResult $result): void
    {
        try {
            $track = $this->trackParser->parse($filename, $contents);
        } catch (\Throwable $e) {
            $result->errors[] = \sprintf('%s : %s', basename($filename), $e->getMessage());

            return;
        }

        if (null === $track) {
            ++$result->ignored;

            return;
        }

        // Trace de l'export Strava : on enrichit la ligne du CSV. Sinon, la trace est une activité.
        $activity = $this->byTrackFile[ActivityRepository::trackKey($filename)] ?? null;
        if (null === $activity) {
            $upsert = $this->activities->upsert($track->activity);
            $this->count($upsert->outcome, $result);
            $activity = $upsert->activity;
        }

        $this->attachSplits($activity, $track, $result);
    }

    /**
     * Lit une trace et en rattache les temps de passage à une activité déjà connue
     * (utilisé par la synchro intervals.icu).
     */
    public function attachTrack(Activity $activity, string $filename, string $contents, ImportResult $result): void
    {
        try {
            $track = $this->trackParser->parse($filename, $contents);
        } catch (\Throwable $e) {
            $result->errors[] = \sprintf('%s : %s', basename($filename), $e->getMessage());

            return;
        }

        if (null === $track) {
            ++$result->ignored;

            return;
        }

        $this->attachSplits($activity, $track, $result);
    }

    private function attachSplits(Activity $activity, ParsedTrack $track, ImportResult $result): void
    {
        // Pas de temps de passage, de relief ni de carte pertinents en piscine.
        $ground = Sport::Swim === $activity->sport ? null : $this->elevationCorrector->correct($track);
        if (null !== $ground) {
            ++$result->groundElevation;
        } elseif (Sport::Swim !== $activity->sport && $this->hasPosition($track)) {
            ++$result->groundElevationMissing;
        }
        $splits = Sport::Swim === $activity->sport ? [] : $this->splitCalculator->splits($ground ?? $track);
        $route = Sport::Swim === $activity->sport ? [] : $this->routeSimplifier->simplify($track->points);

        if ($activity->replaceTrackData($splits, $this->splitCalculator->sustainedMaxHeartRate($track->points), $ground?->groundElevationGain, $route)) {
            ++$result->withSplits;
        }
    }

    private function hasPosition(ParsedTrack $track): bool
    {
        foreach ($track->points as $point) {
            if ($point->hasPosition()) {
                return true;
            }
        }

        return false;
    }

    private function count(UpsertOutcome $outcome, ImportResult $result): void
    {
        match ($outcome) {
            UpsertOutcome::Created => ++$result->created,
            UpsertOutcome::Updated => ++$result->updated,
            UpsertOutcome::Unchanged => ++$result->unchanged,
        };
    }
}
