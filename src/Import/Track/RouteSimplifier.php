<?php

declare(strict_types=1);

namespace App\Import\Track;

/**
 * Réduit une trace aux points utiles pour la carte : un point géolocalisé tous les SAMPLE_METERS
 * (plus espacés sur une très longue sortie), sans jamais perdre le départ ni l'arrivée.
 */
final class RouteSimplifier
{
    public const MAX_POINTS = 2000;
    private const SAMPLE_METERS = 20.0;

    /**
     * @param list<TrackPoint> $points
     *
     * @return list<array{0: float, 1: float, 2: float, 3: int}> latitude, longitude, distance (m),
     *                                                           secondes depuis le départ ; vide sans GPS
     */
    public function simplify(array $points): array
    {
        $located = array_values(array_filter($points, static fn (TrackPoint $p): bool => $p->hasPosition()));
        if (\count($located) < 2) {
            return [];
        }

        $first = $located[0];
        $last = $located[array_key_last($located)];
        $step = max(self::SAMPLE_METERS, ($last->distanceMeters - $first->distanceMeters) / (self::MAX_POINTS - 2));

        $kept = [];
        $next = $first->distanceMeters;
        foreach ($located as $point) {
            if ($point->distanceMeters >= $next) {
                $kept[] = $point;
                $next = $point->distanceMeters + $step;
            }
        }
        if ($kept[\count($kept) - 1] !== $last) {
            $kept = [...\array_slice($kept, 0, self::MAX_POINTS - 1), $last];
        }

        return array_map(static fn (TrackPoint $p): array => [
            round((float) $p->latitude, 5),
            round((float) $p->longitude, 5),
            round($p->distanceMeters, 1),
            $p->timestamp - $first->timestamp,
        ], $kept);
    }
}
