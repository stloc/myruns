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
use App\Weather\OpenMeteoWeather;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Importe les activités depuis :
 *  - l'archive ZIP de l'export de compte Strava (ou le dossier décompressé) : activities.csv + traces ;
 *  - l'archive ZIP de l'export de compte Garmin Connect (ou le dossier décompressé) : résumé JSON
 *    (*_summarizedActivities.json) + FIT des archives UploadedFiles_*.zip qu'elle contient ;
 *  - un dossier de fichiers FIT/GPX/TCX alimenté par Tapiriik, une extension navigateur, etc.
 *
 * Le CSV fait foi pour le résumé ; les traces apportent les temps de passage et la FC tenue,
 * rattachés à la ligne du CSV par le nom du fichier (colonne « Nom du fichier »). Le résumé Garmin
 * ne cite pas ses FIT : chacun rejoint sa ligne par l'heure de départ (ActivityRepository::upsert()).
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
    /** Archives des FIT dans l'export Garmin (DI_CONNECT/DI-Connect-Uploaded-Files/). */
    private const GARMIN_UPLOADS = 'UploadedFiles_*.zip';

    /** @var array<string, Activity> activités du CSV indexées par fichier de trace, pour l'import en cours */
    private array $byTrackFile = [];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly StravaCsvParser $csvParser,
        private readonly TrackFileParser $trackParser,
        private readonly SplitCalculator $splitCalculator,
        private readonly ElevationCorrector $elevationCorrector,
        private readonly CityFinder $geocoder,
        private readonly OpenMeteoWeather $weather,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly RouteSimplifier $routeSimplifier = new RouteSimplifier(),
        private readonly GarminExportParser $garminParser = new GarminExportParser(),
    ) {
    }

    /**
     * Import complet en une fois (ligne de commande).
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
        $this->cleanUp($path);

        return $result;
    }

    /**
     * 1ʳᵉ étape : importe les résumés (activities.csv, résumé Garmin), rapides, et compte les traces à lire ensuite.
     *
     * @return int nombre de traces à passer à continue()
     */
    public function begin(string $path, ImportResult $result): int
    {
        $path = $this->resolve($path);

        if (is_file($path) && self::isZip($path)) {
            $this->importZipSummaries($path, $result);
        } elseif (is_dir($path)) {
            foreach ((new Finder())->files()->in($path)->name('activities.csv')->sortByName() as $csv) {
                $stream = fopen($csv->getPathname(), 'r');
                if (false !== $stream) {
                    $this->importCsv($stream, $result);
                    fclose($stream);
                }
            }
            foreach ((new Finder())->files()->in($path)->name('*summarizedActivities.json')->sortByName() as $json) {
                $this->importGarminSummaries($json->getContents(), $result);
            }
            foreach ($this->nestedZips($path) as $zip) {
                $this->importZipSummaries($zip, $result);
            }
        } elseif (!is_file($path) || !TrackFileParser::supports($path)) {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est ni une archive ZIP Strava ou Garmin, ni un dossier, ni un fichier FIT/GPX/TCX.', $path));
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

    /**
     * Efface les archives imbriquées extraites pour l'import de $path (FIT de l'export Garmin).
     */
    public function cleanUp(string $path): void
    {
        $path = $this->resolve($path);
        $archives = is_dir($path) ? $this->nestedZips($path) : [$path];
        foreach ($archives as $archive) {
            foreach (glob(self::extractionPrefix($archive).'-*.zip') ?: [] as $extracted) {
                @unlink($extracted);
            }
        }
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
     * Archives posées à la racine du dossier, et celles des FIT de l'export Garmin décompressé.
     *
     * @return list<string>
     */
    private function nestedZips(string $directory): array
    {
        $zips = [];
        foreach ((new Finder())->files()->in($directory)->depth(0)->name('*.zip') as $zip) {
            $zips[$zip->getPathname()] = true;
        }
        foreach ((new Finder())->files()->in($directory)->name(self::GARMIN_UPLOADS) as $zip) {
            $zips[$zip->getPathname()] = true;
        }
        $zips = array_keys($zips);
        sort($zips);

        return $zips;
    }

    private function importZipSummaries(string $path, ImportResult $result): void
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
                } elseif (GarminExportParser::supports($name)) {
                    $this->importGarminSummaries((string) $zip->getFromIndex($i), $result);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Traces de l'archive, y compris celles des archives qu'elle contient (export Garmin : FIT dans
     * UploadedFiles_*.zip). Une archive imbriquée est extraite une fois dans le dossier temporaire,
     * réutilisée d'une étape à l'autre, puis effacée par cleanUp().
     *
     * @return \Generator<string, \Closure(): string>
     */
    private function zipTracks(string $path, bool $withNested = true): \Generator
    {
        $zip = $this->openZip($path);
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (TrackFileParser::supports($name)) {
                    $index = $i;
                    yield $name => static fn (): string => (string) $zip->getFromIndex($index);
                } elseif ($withNested && self::isZip($name)) {
                    yield from $this->zipTracks($this->extract($zip, $i, $path), false);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function extract(\ZipArchive $zip, int $index, string $archive): string
    {
        $target = self::extractionPrefix($archive).'-'.$index.'.zip';
        $stat = $zip->statIndex($index);
        if (is_file($target) && false !== $stat && filesize($target) === $stat['size']) {
            return $target;
        }

        $source = $zip->getStream((string) $zip->getNameIndex($index));
        $destination = fopen($target, 'w');
        if (false === $source || false === $destination) {
            throw new \RuntimeException(\sprintf('Impossible d\'extraire « %s » de l\'archive.', $zip->getNameIndex($index)));
        }
        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($source);
            fclose($destination);
        }

        return $target;
    }

    /**
     * Préfixe des archives extraites de $archive : change avec son contenu (taille, date).
     */
    private static function extractionPrefix(string $archive): string
    {
        $real = realpath($archive);
        $key = false === $real ? $archive : $real.'|'.filesize($real).'|'.filemtime($real);

        return sys_get_temp_dir().'/myruns-'.substr(sha1($key), 0, 16);
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

    private function importGarminSummaries(string $json, ImportResult $result): void
    {
        foreach ($this->garminParser->parse($json) as $activity) {
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
            if (null === $activity) {
                return; // Sortie supprimée par l'utilisateur.
            }
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
        $weather = null === $start ? null : $this->weather->during((float) $start->latitude, (float) $start->longitude, $activity->startDate, $activity->elapsedTimeSeconds);

        $changed = $activity->replaceTrackData($splits, $this->splitCalculator->sustainedMaxHeartRate($track->points), $ground?->groundElevationGain, $route, $city);
        if (null !== $weather && $activity->replaceWeather($weather)) {
            $changed = true;
        }
        if ($changed) {
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
            UpsertOutcome::Deleted => ++$result->deleted,
        };
    }
}
