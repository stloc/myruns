<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Import\Fit\FitDecoder;
use App\Import\Fit\FitLap;
use App\Import\Track\ParsedTrack;
use App\Import\Track\TrackLap;
use App\Import\Track\TrackPoint;
use App\Repository\ActivityRepository;

/**
 * Lit un fichier de trace FIT, GPX ou TCX (éventuellement compressé en .gz) : celui de l'export
 * Strava, de Tapiriik ou d'une extension de téléchargement en masse.
 *
 * Renvoie le résumé de l'activité, les points de mesure et les tours enregistrés par la montre.
 */
final class TrackFileParser
{
    /** En dessous de cette vitesse, on considère l'athlète à l'arrêt (m/s). */
    private const MOVING_SPEED_THRESHOLD = 0.5;
    private const MAX_GAP_SECONDS = 30;
    private const EARTH_RADIUS_M = 6371000.0;
    private const ELEVATION_NOISE = 2.0;
    private const TCX_NAMESPACE = 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2';
    /** Codes sport FIT → sport de l'application. */
    private const FIT_SPORTS = [1 => Sport::Run, 2 => Sport::Ride, 5 => Sport::Swim, 11 => Sport::Walk, 17 => Sport::Hike];

    private readonly FitDecoder $fitDecoder;

    public function __construct()
    {
        $this->fitDecoder = new FitDecoder();
    }

    public static function supports(string $filename): bool
    {
        return (bool) preg_match('/\.(gpx|tcx|fit)(\.gz)?$/i', $filename);
    }

    public function parse(string $filename, string $contents): ?ParsedTrack
    {
        // Compression détectée par sa signature : certains services (intervals.icu) renvoient
        // le fichier brut malgré l'extension annoncée.
        if (str_starts_with($contents, "\x1f\x8b")) {
            $contents = @gzdecode($contents);
            if (false === $contents) {
                return null;
            }
        }

        $id = 'file:'.basename(preg_replace('/\.gz$/i', '', $filename) ?? $filename);

        if (FitDecoder::isFit($contents)) {
            return $this->parseFit($contents, $id);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            // Certains exports TCX commencent par des espaces avant la déclaration XML.
            $xml = simplexml_load_string(ltrim($contents));
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (false === $xml) {
            return null;
        }

        return match (strtolower($xml->getName())) {
            'gpx' => $this->parseGpx($xml, $id),
            'trainingcenterdatabase' => $this->parseTcx($xml, $id),
            default => null,
        };
    }

    private function parseFit(string $contents, string $id): ?ParsedTrack
    {
        $fit = $this->fitDecoder->decode($contents);

        $points = [];
        foreach ($fit->records as $record) {
            if (null !== $record->distanceMeters) {
                $points[] = new TrackPoint($record->timestamp, $record->distanceMeters, $record->heartRate, $record->altitudeMeters, $record->latitude, $record->longitude);
            }
        }
        if (\count($points) < 2) {
            return null;
        }

        $laps = array_map(static fn (FitLap $lap): TrackLap => new TrackLap(
            $lap->distanceMeters,
            $lap->timerSeconds,
            $lap->averageHeartRate,
            $lap->maxHeartRate,
            null === $lap->ascentMeters ? null : (float) $lap->ascentMeters,
        ), $fit->laps);

        $sport = self::FIT_SPORTS[$fit->sport ?? 0] ?? Sport::Other;

        return new ParsedTrack($this->summarize($id, '', $sport, $points), $points, $laps);
    }

    private function parseGpx(\SimpleXMLElement $xml, string $id): ?ParsedTrack
    {
        $xml->registerXPathNamespace('g', $xml->getNamespaces()[''] ?? 'http://www.topografix.com/GPX/1/1');

        $points = [];
        $distance = 0.0;
        $previous = null;
        foreach ($xml->xpath('//g:trkpt') ?: [] as $node) {
            $time = isset($node->time) ? strtotime((string) $node->time) : false;
            if (false === $time) {
                continue;
            }
            $lat = (float) $node['lat'];
            $lon = (float) $node['lon'];
            if (null !== $previous) {
                $distance += $this->haversine($previous[0], $previous[1], $lat, $lon);
            }
            $previous = [$lat, $lon];

            $hr = null;
            foreach ($node->xpath('.//*[local-name()="hr"]') ?: [] as $hrNode) {
                $hr = (int) $hrNode;
            }
            $points[] = new TrackPoint($time, $distance, $hr, isset($node->ele) ? (float) $node->ele : null, $lat, $lon);
        }
        if (\count($points) < 2) {
            return null;
        }

        $trk = $xml->trk[0] ?? null;
        $type = null !== $trk && isset($trk->type) ? (string) $trk->type : '';
        $name = null !== $trk && isset($trk->name) ? (string) $trk->name : '';

        return new ParsedTrack($this->summarize($id, $name, Sport::fromLabel('' !== $type ? $type : $name)->refinedByName($name), $points), $points);
    }

    private function parseTcx(\SimpleXMLElement $xml, string $id): ?ParsedTrack
    {
        $xml->registerXPathNamespace('t', self::TCX_NAMESPACE);
        $node = ($xml->xpath('//t:Activity') ?: [])[0] ?? null;
        if (null === $node) {
            return null;
        }
        $node->registerXPathNamespace('t', self::TCX_NAMESPACE);

        $start = strtotime((string) $node->Id);
        if (false === $start) {
            return null;
        }

        $laps = [];
        foreach ($node->xpath('t:Lap') ?: [] as $lap) {
            $laps[] = new TrackLap(
                (float) $lap->DistanceMeters,
                (float) $lap->TotalTimeSeconds,
                isset($lap->AverageHeartRateBpm) ? (int) $lap->AverageHeartRateBpm->Value : null,
                isset($lap->MaximumHeartRateBpm) ? (int) $lap->MaximumHeartRateBpm->Value : null,
            );
        }

        $points = [];
        foreach ($node->xpath('.//t:Trackpoint') ?: [] as $point) {
            $time = strtotime((string) $point->Time);
            if (false === $time || !isset($point->DistanceMeters)) {
                continue;
            }
            $points[] = new TrackPoint(
                $time,
                (float) $point->DistanceMeters,
                isset($point->HeartRateBpm) ? (int) $point->HeartRateBpm->Value : null,
                isset($point->AltitudeMeters) ? (float) $point->AltitudeMeters : null,
                isset($point->Position) ? (float) $point->Position->LatitudeDegrees : null,
                isset($point->Position) ? (float) $point->Position->LongitudeDegrees : null,
            );
        }

        $name = (string) ($xml->xpath('//t:Activity/t:Notes')[0] ?? '');
        $sport = Sport::fromLabel((string) $node['Sport'])->refinedByName($name);
        $lapTime = array_sum(array_map(static fn (TrackLap $l): float => $l->seconds, $laps));
        $lapDistance = array_sum(array_map(static fn (TrackLap $l): float => $l->distanceMeters, $laps));
        $lapHr = array_sum(array_map(static fn (TrackLap $l): float => ($l->averageHeartRate ?? 0) * $l->seconds, $laps));
        $lapAverageHr = $lapTime > 0 && $lapHr > 0 ? round($lapHr / $lapTime, 1) : null;

        // Les tours donnent la distance et la durée officielles ; les points, le D+ et le temps en mouvement.
        $summary = \count($points) >= 2 ? $this->summarize($id, $name, $sport, $points) : null;
        $activity = new Activity(
            id: $id,
            source: ActivityRepository::SOURCE_FILE,
            name: $name,
            sport: $sport,
            startDate: $this->utc($start),
            distanceMeters: $lapDistance > 0 ? $lapDistance : ($summary->distanceMeters ?? 0.0),
            movingTimeSeconds: $summary->movingTimeSeconds ?? (int) $lapTime,
            elapsedTimeSeconds: (int) ($lapTime > 0 ? $lapTime : ($summary->elapsedTimeSeconds ?? 0)),
            elevationGain: $summary?->elevationGain,
            averageHeartRate: $lapAverageHr ?? $summary?->averageHeartRate,
            maxHeartRate: $summary?->maxHeartRate,
        );

        return new ParsedTrack($activity, $points, $laps);
    }

    /**
     * @param non-empty-list<TrackPoint> $points
     */
    private function summarize(string $id, string $name, Sport $sport, array $points): Activity
    {
        $moving = 0;
        $gain = 0.0;
        $hrValues = [];
        $lastAltitude = $points[0]->altitudeMeters;

        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $dt = $b->timestamp - $a->timestamp;
            if ($dt > 0 && $dt <= self::MAX_GAP_SECONDS && ($b->distanceMeters - $a->distanceMeters) / $dt >= self::MOVING_SPEED_THRESHOLD) {
                $moving += $dt;
            }
            if (null !== $b->altitudeMeters) {
                if (null === $lastAltitude) {
                    $lastAltitude = $b->altitudeMeters;
                } elseif (abs($b->altitudeMeters - $lastAltitude) >= self::ELEVATION_NOISE) {
                    $gain += max(0.0, $b->altitudeMeters - $lastAltitude);
                    $lastAltitude = $b->altitudeMeters;
                }
            }
            if (null !== $b->heartRate && $b->heartRate > 0) {
                $hrValues[] = $b->heartRate;
            }
        }

        $first = $points[0];
        $last = $points[array_key_last($points)];

        return new Activity(
            id: $id,
            source: ActivityRepository::SOURCE_FILE,
            name: $name,
            sport: $sport,
            startDate: $this->utc($first->timestamp),
            distanceMeters: round($last->distanceMeters - $first->distanceMeters, 1),
            movingTimeSeconds: $moving,
            elapsedTimeSeconds: $last->timestamp - $first->timestamp,
            elevationGain: null !== $lastAltitude ? round($gain) : null,
            averageHeartRate: [] !== $hrValues ? round(array_sum($hrValues) / \count($hrValues), 1) : null,
            maxHeartRate: [] !== $hrValues ? (float) max($hrValues) : null,
        );
    }

    private function utc(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($timestamp)->setTimezone(new \DateTimeZone('UTC'));
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
