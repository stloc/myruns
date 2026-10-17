<?php

declare(strict_types=1);

namespace App\Tests\Unit\Route;

use App\Route\RouteMatcher;
use PHPUnit\Framework\TestCase;

final class RouteMatcherTest extends TestCase
{
    /** Boucle rectangulaire de 1 km × 500 m (3 km), départ au coin sud-ouest, sens horaire. */
    private const LOOP = [[0, 0], [0, 500], [1000, 500], [1000, 0], [0, 0]];

    public function testSameLoopWithGpsOffsetMatches(): void
    {
        // Autre montre : toute la trace décalée de 25 m, points moins serrés.
        self::assertTrue((new RouteMatcher())->sameRoute(self::route(self::LOOP), self::route(self::LOOP, offset: 25, step: 35)));
    }

    public function testLoopInTheOtherDirectionIsAnotherRoute(): void
    {
        self::assertFalse((new RouteMatcher())->sameRoute(self::route(self::LOOP), self::route(array_reverse(self::LOOP))));
    }

    public function testOutAndBackMatchesItself(): void
    {
        $outAndBack = [[0, 0], [0, 1500], [0, 0]];

        self::assertTrue((new RouteMatcher())->sameRoute(self::route($outAndBack), self::route($outAndBack, offset: 15)));
        // Seulement l'aller : la moitié de la trace ne colle pas.
        self::assertFalse((new RouteMatcher())->sameRoute(self::route($outAndBack), self::route([[0, 0], [0, 1500]])));
    }

    public function testDetourMakesAnotherRoute(): void
    {
        // Même boucle avec un aller-retour de 300 m vers le nord au milieu du côté nord (+20 %).
        $detour = [[0, 0], [0, 500], [500, 500], [500, 800], [500, 500], [1000, 500], [1000, 0], [0, 0]];

        self::assertFalse((new RouteMatcher())->sameRoute(self::route(self::LOOP), self::route($detour)));
        self::assertFalse((new RouteMatcher())->sameRoute(self::route($detour), self::route(self::LOOP)));
    }

    public function testSameStartOtherDirectionIsAnotherRoute(): void
    {
        self::assertFalse((new RouteMatcher())->sameRoute(self::route([[0, 0], [0, 2000]]), self::route([[0, 0], [2000, 0]])));
    }

    public function testRouteWithoutPointsNeverMatches(): void
    {
        self::assertFalse((new RouteMatcher())->sameRoute([], self::route(self::LOOP)));
    }

    /**
     * Trace simplifiée qui relie les sommets donnés en mètres ([est, nord] depuis 48° N, 4° O).
     *
     * @param list<array{0: int, 1: int}> $vertices
     *
     * @return list<array{0: float, 1: float, 2: float, 3: int}>
     */
    private static function route(array $vertices, float $offset = 0.0, float $step = 20.0): array
    {
        $points = [];
        $distance = 0.0;
        for ($i = 1, $count = \count($vertices); $i < $count; ++$i) {
            [$x0, $y0] = $vertices[$i - 1];
            [$x1, $y1] = $vertices[$i];
            $length = hypot($x1 - $x0, $y1 - $y0);
            for ($d = 0.0; $d < $length; $d += $step) {
                $points[] = self::point($x0 + ($x1 - $x0) * $d / $length + $offset, $y0 + ($y1 - $y0) * $d / $length + $offset, $distance + $d);
            }
            $distance += $length;
        }
        [$x, $y] = $vertices[\count($vertices) - 1];
        $points[] = self::point($x + $offset, $y + $offset, $distance);

        return $points;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: int}
     */
    private static function point(float $east, float $north, float $distance): array
    {
        return [48 + $north / 111195, -4 + $east / (111195 * cos(deg2rad(48))), $distance, (int) round($distance / 3)];
    }
}
