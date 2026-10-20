<?php

declare(strict_types=1);

namespace App\Import\Track;

/**
 * Réduit une trace aux points du profil de la page détail : un point tous les SAMPLE_METERS
 * (plus espacés sur une longue sortie), FC et cadence moyennées depuis le point précédent,
 * sans jamais perdre le départ ni l'arrivée. Fonctionne sans GPS (tapis de course).
 */
final class StreamSampler
{
    public const MAX_POINTS = 600;
    private const SAMPLE_METERS = 10.0;

    /**
     * @param list<TrackPoint> $points trace, altitude du terrain si elle a pu être calculée
     *
     * @return list<array{0: float, 1: int, 2: ?float, 3: ?int, 4: ?float}> distance (m), secondes depuis
     *                                                                      le départ, altitude (m), FC, cadence (cycles/min) ; vide sans distance
     */
    public function sample(array $points): array
    {
        if (\count($points) < 2) {
            return [];
        }

        $first = $points[0];
        $lastIndex = array_key_last($points);
        $total = $points[$lastIndex]->distanceMeters - $first->distanceMeters;
        if ($total <= 0) {
            return [];
        }
        $step = max(self::SAMPLE_METERS, $total / (self::MAX_POINTS - 1));

        $samples = [];
        $window = [];
        $next = $first->distanceMeters;
        foreach ($points as $i => $point) {
            $window[] = $point;
            if ($point->distanceMeters < $next && $i !== $lastIndex) {
                continue;
            }

            $samples[] = [
                round($point->distanceMeters - $first->distanceMeters, 1),
                $point->timestamp - $first->timestamp,
                null === $point->altitudeMeters ? null : round($point->altitudeMeters, 1),
                self::average(array_map(static fn (TrackPoint $p): ?float => null === $p->heartRate ? null : (float) $p->heartRate, $window), 0),
                self::average(array_map(static fn (TrackPoint $p): ?float => $p->cadence, $window), 1),
            ];
            $window = [];
            $next = $point->distanceMeters + $step;
        }

        return $samples;
    }

    /**
     * @param list<?float> $values
     *
     * @return ($precision is 0 ? ?int : ?float)
     */
    private static function average(array $values, int $precision): int|float|null
    {
        $values = array_values(array_filter($values, static fn (?float $v): bool => null !== $v));
        if ([] === $values) {
            return null;
        }
        $average = round(array_sum($values) / \count($values), $precision);

        return 0 === $precision ? (int) $average : $average;
    }
}
