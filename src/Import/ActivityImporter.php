<?php

declare(strict_types=1);

namespace App\Import;

use App\Elevation\ElevationCorrector;
use App\Entity\Activity;
use App\Enum\Sport;
use App\Geocoding\CityFinder;
use App\Import\Track\ParsedTrack;
use App\Import\Track\RouteSimplifier;
use App\Import\Track\SplitCalculator;
use App\Import\Track\TrackPoint;
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
 *
 * Les traces sont lentes à la 1ʳᵉ lecture (appels IGN) : la page web les lit par étapes
 * (begin() puis continue() à répétition), la ligne de commande d'une traite (import()).
 */
final class ActivityImporter
{
    private const TRACK_PATTERNS = ['*.fit', '*.gpx', '*.tcx', '*.fit.gz', '*.gpx.gz', '*.tcx.gz'];
    /** Durée d'un lot en ligne de commande, entre deux enregistrements en base. */
    private const CLI_BATCH_SECONDS = 30.0;

    /** @var array<string, Activity> activités du CSV indexées par fichier de trace, pour l'import en cours */
    private array $byTrackFile = [];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly StravaCsvParser $csvParser,
        private readonly TrackFileParser $trackParser,
        private readonly SplitCalculator $splitCalculator,
        private readonly ElevationCorrector $elevationCorrector,
        private readonly CityFinder $geocoder,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly RouteSimplifier $routeSimplifier = new RouteSimplifier(),
    ) {
    }

    /**
     * Import complet en une fois (ligne de commande, TUI).
     *
     * @param string                          $path       chemin absolu, ou relatif au dossier du projet (seul dossier visible sous DDEV)
     * @param (\Closure(int, int): void)|null $onProgress appelé après chaque lot de traces (traitées, total)
     */
    public function import(string $path, ?\Closure $onProgress = null): ImportResult
    {
        $result = new ImportResult();
        $total = $this->begin($path, $result);

        // Enregistré par lots : une interruption ne perd que le lot en cours.
        $done = 0;
        while ($done < $total) {
            $next = $this->continue($path, $done, $result, microtime(true) + self::CLI_BATCH_SECONDS);
            if ($next <= $done) {
                break; // Traces supprimées du dossier entre-temps.
            }
            $done = $next;
            if (null !== $onProgress) {
                $onProgress($done, $total);
            }
        }

        return $result;
    }

    /**
     * 1ʳᵉ étape : importe les résumés (activities.csv), rapides, et compte les traces à lire ensuite.
     *
     * @return int nombre de traces à passer à continue()
     */
    public function begin(string $path, ImportResult $result): int
    {
        $path = $this->resolve($path);

        if (is_file($path) && self::isZip($path)) {
            $this->importZipCsv($path, $result);
        } elseif (is_dir($path)) {
            foreach ((new Finder())->files()->in($path)->name('activities.csv')->sortByName() as $csv) {
                $stream = fopen($csv->getPathname(), 'r');
                if (false !== $stream) {
                    $this->importCsv($stream, $result);
                    fclose($stream);
                }
            }
            foreach ($this->nestedZips($path) as $zip) {
                $this->importZipCsv($zip, $result);
            }
        } elseif (!is_file($path) || !TrackFileParser::supports($path)) {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est ni une archive ZIP Strava, ni un dossier, ni un fichier FIT/GPX/TCX.', $path));
        }

        $this->activities->flush();

        return iterator_count($this->tracks($path));
    }

    /**
     * Étapes suivantes : lit les traces à partir de la position donnée (temps de passage, relief et
     * ville via l'IGN, plusieurs secondes chacune la 1ʳᵉ fois) jusqu'à l'échéance, puis enregistre.
     * Au moins une trace est lue à chaque appel.
     *
     * @param float|null $deadline horodatage microtime() après lequel s'arrêter, null pour tout lire
     *
     * @return int position de la prochaine trace (= total quand tout est lu)
     */
    public function continue(string $path, int $offset, ImportResult $result, ?float $deadline = null): int
    {
        $path = $this->resolve($path);
        $this->byTrackFile = $this->activities->indexByTrackFile();

        $position = 0;
        foreach ($this->tracks($path) as $name => $contents) {
            if ($position >= $offset) {
                if ($position > $offset && null !== $deadline && microtime(true) >= $deadline) {
                    break;
                }
                $this->importTrack($name, $contents(), $result);
            }
            ++$position;
        }

        $this->activities->flush();

        return $position;
    }

    private function resolve(string $path): string
    {
        return '' !== $path && !Path::isAbsolute($path) ? Path::join($this->projectDir, $path) : $path;
    }

    private static function isZip(string $path): bool
    {
        return str_ends_with(strtolower($path), '.zip');
    }

    /**
     * Traces à lire, toujours dans le même ordre (la position sert de reprise entre deux étapes).
     * Le contenu n'est lu qu'à la demande : sauter les traces déjà traitées ne coûte rien.
     *
     * @return \Generator<string, \Closure(): string> nom du fichier => lecture du contenu
     */
    private function tracks(string $path): \Generator
    {
        if (is_file($path)) {
            if (self::isZip($path)) {
                yield from $this->zipTracks($path);
            } else {
                yield basename($path) => static fn (): string => (string) file_get_contents($path);
            }

            return;
        }

        foreach ($this->nestedZips($path) as $zip) {
            yield from $this->zipTracks($zip);
        }
        foreach ((new Finder())->files()->in($path)->name(self::TRACK_PATTERNS)->sortByName() as $track) {
            yield $track->getFilename() => static fn (): string => $track->getContents();
        }
    }

    /**
     * @return list<string>
     */
    private function nestedZips(string $directory): array
    {
        $zips = [];
        foreach ((new Finder())->files()->in($directory)->depth(0)->name('*.zip')->sortByName() as $zip) {
            $zips[] = $zip->getPathname();
        }

        return $zips;
    }

    private function importZipCsv(string $path, ImportResult $result): void
    {
        $zip = $this->openZip($path);
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
        } finally {
            $zip->close();
        }
    }

    /**
     * @return \Generator<string, \Closure(): string>
     */
    private function zipTracks(string $path): \Generator
    {
        $zip = $this->openZip($path);
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (TrackFileParser::supports($name)) {
                    $index = $i;
                    yield $name => static fn (): string => (string) $zip->getFromIndex($index);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new \RuntimeException(\sprintf('Impossible d\'ouvrir l\'archive « %s ».', $path));
        }

        return $zip;
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

        $start = $this->firstPosition($track);
        $city = null === $start ? null : $this->geocoder->cityAt((float) $start->latitude, (float) $start->longitude);

        if ($activity->replaceTrackData($splits, $this->splitCalculator->sustainedMaxHeartRate($track->points), $ground?->groundElevationGain, $route, $city)) {
            ++$result->withSplits;
        }
    }

    private function hasPosition(ParsedTrack $track): bool
    {
        return null !== $this->firstPosition($track);
    }

    private function firstPosition(ParsedTrack $track): ?TrackPoint
    {
        foreach ($track->points as $point) {
            if ($point->hasPosition()) {
                return $point;
            }
        }

        return null;
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
