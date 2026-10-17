<?php

declare(strict_types=1);

namespace App\Route;

/**
 * Reconnaît deux traces du même parcours : chacune suit l'autre, dans le même sens, sur presque
 * toute sa longueur. Une boucle faite à l'envers ou une variante (détour, raccourci) est un autre parcours.
 */
final class RouteMatcher
{
    private const METERS_PER_DEGREE = 111195.0;
    /** Écart toléré entre les deux traces (erreur GPS des deux montres). */
    private const TOLERANCE_METERS = 60.0;
    /** Part minimale de chaque trace qui doit longer l'autre. */
    private const MIN_COVERAGE = 0.9;
    /** Points contrôlés sur la trace comparée. */
    private const CHECK_STEP_METERS = 50.0;
    /** Points de la trace de référence, assez serrés pour mesurer l'écart à 10 m près. */
    private const REFERENCE_STEP_METERS = 10.0;
    /** Avance maximale sur la référence entre deux points contrôlés (au-delà : les traces divergent). */
    private const MIN_LOOKAHEAD_METERS = 1000.0;

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: int}> $a trace simplifiée (latitude, longitude, …)
     * @param list<array{0: float, 1: float, 2: float, 3: int}> $b
     */
    public function sameRoute(array $a, array $b): bool
    {
        if (\count($a) < 2 || \count($b) < 2) {
            return false;
        }

        // Projection plane locale autour du départ de $a : quelques mètres d'erreur sur une sortie.
        $originLatitude = $a[0][0];
        $originLongitude = $a[0][1];
        $a = $this->project($a, $originLatitude, $originLongitude);
        $b = $this->project($b, $originLatitude, $originLongitude);

        return $this->coverage($a, $b) >= self::MIN_COVERAGE && $this->coverage($b, $a) >= self::MIN_COVERAGE;
    }

    /**
     * Part des points de $route qui longent $reference, dans l'ordre de la référence.
     *
     * @param list<array{0: float, 1: float}> $route
     * @param list<array{0: float, 1: float}> $reference
     */
    private function coverage(array $route, array $reference): float
    {
        $checks = $this->resample($route, self::CHECK_STEP_METERS);
        $dense = $this->resample($reference, self::REFERENCE_STEP_METERS);
        $lookahead = (int) ceil(max(self::MIN_LOOKAHEAD_METERS, 0.2 * $this->length($reference)) / self::REFERENCE_STEP_METERS);
        $squaredTolerance = self::TOLERANCE_METERS ** 2;

        // On avance sur la référence au fil des points contrôlés : un aller-retour ou un croisement
        // ne se raccroche pas au mauvais passage, une boucle à l'envers ne colle pas.
        $cursor = 0;
        $matched = 0;
        $last = \count($dense) - 1;
        foreach ($checks as [$x, $y]) {
            for ($i = $cursor, $end = min($last, $cursor + $lookahead); $i <= $end; ++$i) {
                if (($dense[$i][0] - $x) ** 2 + ($dense[$i][1] - $y) ** 2 <= $squaredTolerance) {
                    ++$matched;
                    $cursor = $i;
                    break;
                }
            }
        }

        return $matched / \count($checks);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: int}> $points
     *
     * @return list<array{0: float, 1: float}> coordonnées en mètres
     */
    private function project(array $points, float $originLatitude, float $originLongitude): array
    {
        $cos = cos(deg2rad($originLatitude));

        return array_map(static fn (array $p): array => [
            ($p[1] - $originLongitude) * $cos * self::METERS_PER_DEGREE,
            ($p[0] - $originLatitude) * self::METERS_PER_DEGREE,
        ], $points);
    }

    /**
     * Points régulièrement espacés le long de la trace (départ et arrivée compris).
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function resample(array $points, float $step): array
    {
        $result = [$points[0]];
        $carried = 0.0; // distance parcourue depuis le dernier point retenu
        for ($i = 1, $count = \count($points); $i < $count; ++$i) {
            [$x0, $y0] = $points[$i - 1];
            [$x1, $y1] = $points[$i];
            $segment = hypot($x1 - $x0, $y1 - $y0);
            $position = $step - $carried;
            while ($position <= $segment) {
                $ratio = $position / $segment;
                $result[] = [$x0 + ($x1 - $x0) * $ratio, $y0 + ($y1 - $y0) * $ratio];
                $position += $step;
            }
            $carried = $segment - ($position - $step);
        }
        $result[] = $points[\count($points) - 1];

        return $result;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    private function length(array $points): float
    {
        $length = 0.0;
        for ($i = 1, $count = \count($points); $i < $count; ++$i) {
            $length += hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]);
        }

        return $length;
    }
}
