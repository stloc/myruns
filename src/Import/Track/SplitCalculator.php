<?php

declare(strict_types=1);

namespace App\Import\Track;

use App\Enum\SplitKind;

/**
 * Calcule, à partir des points d'une trace, les temps de passage au kilomètre (en temps de
 * déplacement, arrêts exclus) et la FC maximale tenue au moins 10 secondes.
 */
final class SplitCalculator
{
    private const SPLIT_METERS = 1000.0;
    /** En dessous de cette vitesse entre deux points, on considère l'athlète à l'arrêt (m/s). */
    private const MOVING_SPEED_THRESHOLD = 0.5;
    /** Un écart plus long entre deux points correspond à une pause du chronomètre. */
    private const MAX_GAP_SECONDS = 30;
    /** Dernier tronçon conservé s'il dépasse cette distance (m). */
    private const MIN_LAST_SPLIT = 100.0;
    private const ELEVATION_NOISE = 2.0;
    private const SUSTAINED_SECONDS = 10;

    /**
     * Passages au km et tours de la montre (seulement s'il y en a plusieurs).
     *
     * @return list<array{kind: SplitKind, distance: float, seconds: float, avg_hr: ?int, max_hr: ?int, elevation_gain: ?float}>
     */
    public function splits(ParsedTrack $track): array
    {
        $splits = $this->kilometers($track->points);
        if (\count($track->laps) > 1) {
            foreach ($track->laps as $lap) {
                $splits[] = [
                    'kind' => SplitKind::Lap,
                    'distance' => round($lap->distanceMeters, 1),
                    'seconds' => round($lap->seconds, 1),
                    'avg_hr' => $lap->averageHeartRate,
                    'max_hr' => $lap->maxHeartRate,
                    'elevation_gain' => $lap->elevationGain,
                ];
            }
        }

        return $splits;
    }

    /**
     * @param list<TrackPoint> $points
     *
     * @return list<array{kind: SplitKind, distance: float, seconds: float, avg_hr: ?int, max_hr: ?int, elevation_gain: ?float}>
     */
    public function kilometers(array $points): array
    {
        $splits = [];
        if (\count($points) < 2) {
            return $splits;
        }

        $boundary = self::SPLIT_METERS;
        $splitStartDistance = 0.0;
        $seconds = 0.0;
        $hrSum = 0.0;
        $hrTime = 0.0;
        $hrMax = null;
        $gain = 0.0;
        $lastAltitude = $points[0]->altitudeMeters;

        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $dt = $b->timestamp - $a->timestamp;
            $dd = $b->distanceMeters - $a->distanceMeters;
            $moving = $dt > 0 && $dt <= self::MAX_GAP_SECONDS && $dd / $dt >= self::MOVING_SPEED_THRESHOLD;

            // Le segment [a, b] peut franchir une ou plusieurs bornes kilométriques : on interpole.
            $segmentStart = $a->distanceMeters;
            while ($dd > 0 && $b->distanceMeters >= $boundary) {
                $partTime = $moving ? $dt * ($boundary - $segmentStart) / $dd : 0.0;
                $seconds += $partTime;
                if (null !== $b->heartRate) {
                    $hrMax = max($hrMax ?? 0, $b->heartRate);
                    if ($partTime > 0) {
                        $hrSum += $b->heartRate * $partTime;
                        $hrTime += $partTime;
                    }
                }
                $splits[] = $this->split($boundary - $splitStartDistance, $seconds, $hrSum, $hrTime, $hrMax, $gain, null !== $lastAltitude);
                $splitStartDistance = $boundary;
                $segmentStart = $boundary;
                $boundary += self::SPLIT_METERS;
                $seconds = 0.0;
                $hrSum = 0.0;
                $hrTime = 0.0;
                $hrMax = null;
                $gain = 0.0;
            }

            $remainingTime = $moving ? $dt * ($b->distanceMeters - $segmentStart) / $dd : 0.0;
            $seconds += $remainingTime;
            if (null !== $b->heartRate) {
                $hrMax = max($hrMax ?? 0, $b->heartRate);
                if ($remainingTime > 0) {
                    $hrSum += $b->heartRate * $remainingTime;
                    $hrTime += $remainingTime;
                }
            }
            if (null !== $b->altitudeMeters) {
                if (null === $lastAltitude) {
                    $lastAltitude = $b->altitudeMeters;
                } elseif (abs($b->altitudeMeters - $lastAltitude) >= self::ELEVATION_NOISE) {
                    $gain += max(0.0, $b->altitudeMeters - $lastAltitude);
                    $lastAltitude = $b->altitudeMeters;
                }
            }
        }

        $last = $points[array_key_last($points)];
        if ($last->distanceMeters - $splitStartDistance >= self::MIN_LAST_SPLIT) {
            $splits[] = $this->split($last->distanceMeters - $splitStartDistance, $seconds, $hrSum, $hrTime, $hrMax, $gain, null !== $lastAltitude);
        }

        return $splits;
    }

    /**
     * FC la plus haute maintenue pendant au moins 10 s : un pic isolé du capteur optique
     * (cadence prise pour le pouls) ne compte pas.
     *
     * @param list<TrackPoint> $points
     */
    public function sustainedMaxHeartRate(array $points): ?int
    {
        $samples = array_values(array_filter($points, static fn (TrackPoint $p): bool => null !== $p->heartRate && $p->heartRate > 0));
        $best = null;
        $count = \count($samples);
        $end = 0;
        /** @var list<int> $window indices des échantillons de la fenêtre, FC croissante (file monotone) */
        $window = [];

        for ($start = 0; $start < $count; ++$start) {
            while ($end < $count && $samples[$end]->timestamp - $samples[$start]->timestamp <= self::SUSTAINED_SECONDS) {
                while ([] !== $window && $samples[$window[\count($window) - 1]]->heartRate >= $samples[$end]->heartRate) {
                    array_pop($window);
                }
                $window[] = $end;
                ++$end;
            }
            // Fenêtre complète uniquement : elle doit couvrir au moins 10 s.
            if ($samples[$end - 1]->timestamp - $samples[$start]->timestamp >= self::SUSTAINED_SECONDS - 1) {
                $minimum = $samples[$window[0]]->heartRate;
                $best = max($best ?? 0, (int) $minimum);
            }
            if ([] !== $window && $window[0] === $start) {
                array_shift($window);
            }
        }

        return $best;
    }

    /**
     * @return array{kind: SplitKind, distance: float, seconds: float, avg_hr: ?int, max_hr: ?int, elevation_gain: ?float}
     */
    private function split(float $distance, float $seconds, float $hrSum, float $hrTime, ?int $hrMax, float $gain, bool $hasAltitude): array
    {
        return [
            'kind' => SplitKind::Kilometer,
            'distance' => round($distance, 1),
            'seconds' => round($seconds, 1),
            'avg_hr' => $hrTime > 0 ? (int) round($hrSum / $hrTime) : null,
            'max_hr' => $hrMax,
            'elevation_gain' => $hasAltitude ? round($gain, 1) : null,
        ];
    }
}
