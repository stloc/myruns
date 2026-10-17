<?php

declare(strict_types=1);

namespace App\Elevation;

use App\Import\Track\ParsedTrack;
use App\Import\Track\TrackPoint;

/**
 * Remplace l'altitude enregistrée par la montre (GPS ou baromètre mal calibré) par celle du
 * terrain sous chaque position GPS, comme le fait Strava avec son modèle d'élévation.
 *
 * Réglages calibrés sur des sorties en Bretagne dont Strava avait corrigé le D+ :
 * un point tous les 10 m, moyenne glissante sur 5 points, seuil de 1 m entre deux paliers.
 */
final class ElevationCorrector
{
    public const ELEVATION_NOISE = 1.0;
    private const SAMPLE_METERS = 10.0;
    private const SMOOTHING_RADIUS = 2;

    public function __construct(private readonly IgnAltimetry $altimetry)
    {
    }

    /**
     * @return ParsedTrack|null la trace avec l'altitude du terrain, ou null si elle est impossible
     *                          à obtenir (pas de GPS, hors de France, service indisponible)
     */
    public function correct(ParsedTrack $track): ?ParsedTrack
    {
        $samples = $this->sample($track->points);
        if (\count($samples) < 2) {
            return null;
        }

        $elevations = $this->altimetry->elevations(array_map(static fn (TrackPoint $p): array => [(float) $p->latitude, (float) $p->longitude], $samples));
        if (null === $elevations) {
            return null;
        }
        $elevations = self::smooth($elevations);

        $distances = array_map(static fn (TrackPoint $p): float => $p->distanceMeters, $samples);
        $points = array_map(static fn (TrackPoint $p): TrackPoint => $p->withAltitude(self::interpolate($distances, $elevations, $p->distanceMeters)), $track->points);

        return new ParsedTrack($track->activity, $points, $track->laps, round(self::gain($elevations), 1));
    }

    /**
     * Dénivelé positif cumulé, en ignorant les variations inférieures au seuil (bruit).
     *
     * @param list<float> $altitudes
     */
    public static function gain(array $altitudes, float $noise = self::ELEVATION_NOISE): float
    {
        $gain = 0.0;
        $last = $altitudes[0] ?? 0.0;
        foreach ($altitudes as $altitude) {
            if (abs($altitude - $last) >= $noise) {
                $gain += max(0.0, $altitude - $last);
                $last = $altitude;
            }
        }

        return $gain;
    }

    /**
     * Un point géolocalisé tous les SAMPLE_METERS (plus espacés sur une très longue sortie).
     *
     * @param list<TrackPoint> $points
     *
     * @return list<TrackPoint>
     */
    private function sample(array $points): array
    {
        $located = array_values(array_filter($points, static fn (TrackPoint $p): bool => $p->hasPosition()));
        if (\count($located) < 2) {
            return [];
        }

        $length = $located[array_key_last($located)]->distanceMeters - $located[0]->distanceMeters;
        $step = max(self::SAMPLE_METERS, $length / (IgnAltimetry::MAX_POINTS - 1));

        $samples = [];
        $next = $located[0]->distanceMeters;
        foreach ($located as $point) {
            if ($point->distanceMeters >= $next) {
                $samples[] = $point;
                $next = $point->distanceMeters + $step;
            }
        }

        return \array_slice($samples, 0, IgnAltimetry::MAX_POINTS);
    }

    /**
     * Moyenne glissante : gomme les talus et haies qui font sauter le modèle de terrain.
     *
     * @param list<float> $values
     *
     * @return list<float>
     */
    private static function smooth(array $values): array
    {
        $smoothed = [];
        $count = \count($values);
        for ($i = 0; $i < $count; ++$i) {
            $window = \array_slice($values, max(0, $i - self::SMOOTHING_RADIUS), min($i, self::SMOOTHING_RADIUS) + 1 + min($count - 1 - $i, self::SMOOTHING_RADIUS));
            $smoothed[] = array_sum($window) / \count($window);
        }

        return $smoothed;
    }

    /**
     * Altitude à une distance donnée, interpolée entre les deux échantillons qui l'encadrent.
     *
     * @param list<float> $distances croissantes
     * @param list<float> $altitudes même taille que $distances
     */
    private static function interpolate(array $distances, array $altitudes, float $distance): float
    {
        $last = \count($distances) - 1;
        if ($distance <= $distances[0]) {
            return $altitudes[0];
        }
        if ($distance >= $distances[$last]) {
            return $altitudes[$last];
        }

        // Recherche dichotomique du segment [low, low + 1] qui contient la distance.
        $low = 0;
        $high = $last;
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            if ($distances[$middle] <= $distance) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }
        $span = $distances[$high] - $distances[$low];
        $ratio = $span > 0 ? ($distance - $distances[$low]) / $span : 0.0;

        return $altitudes[$low] + $ratio * ($altitudes[$high] - $altitudes[$low]);
    }
}
