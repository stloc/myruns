<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Track\RouteSimplifier;
use App\Import\Track\TrackPoint;
use PHPUnit\Framework\TestCase;

final class RouteSimplifierTest extends TestCase
{
    public function testKeepsOnePointEvery20MetersWithStartAndFinish(): void
    {
        // 1 005 m plein nord, un point par seconde à 3 m/s.
        $points = [];
        for ($t = 0; $t <= 335; ++$t) {
            $points[] = new TrackPoint(1767600000 + $t, $t * 3.0, latitude: 48 + $t * 3 / 111195, longitude: -4.0);
        }

        $route = (new RouteSimplifier())->simplify($points);

        self::assertCount(48 + 1, $route, 'Un point tous les 21 m (premier relevé à 20 m ou plus), plus l\'arrivée');
        self::assertSame([48.0, -4.0, 0.0, 0], $route[0]);
        self::assertSame([round(48 + 1005 / 111195, 5), -4.0, 1005.0, 335], $route[\count($route) - 1]);
        self::assertSame([48.00019, -4.0, 21.0, 7], $route[1]);
    }

    public function testIgnoresPointsWithoutPosition(): void
    {
        $route = (new RouteSimplifier())->simplify([
            new TrackPoint(1767600000, 0.0),
            new TrackPoint(1767600010, 30.0, latitude: 48.0, longitude: -4.0),
            new TrackPoint(1767600020, 60.0),
            new TrackPoint(1767600030, 90.0, latitude: 48.0005, longitude: -4.0),
        ]);

        self::assertSame([[48.0, -4.0, 30.0, 0], [48.0005, -4.0, 90.0, 20]], $route);
    }

    public function testWithoutGpsThereIsNoRoute(): void
    {
        self::assertSame([], (new RouteSimplifier())->simplify([new TrackPoint(1767600000, 0.0), new TrackPoint(1767600010, 30.0)]));
        self::assertSame([], (new RouteSimplifier())->simplify([new TrackPoint(1767600000, 0.0, latitude: 48.0, longitude: -4.0)]));
    }

    public function testVeryLongActivityIsCapped(): void
    {
        // 200 km, un point tous les 5 m.
        $points = [];
        for ($i = 0; $i <= 40000; ++$i) {
            $points[] = new TrackPoint(1767600000 + $i, $i * 5.0, latitude: 48 + $i * 5 / 111195, longitude: -4.0);
        }

        $route = (new RouteSimplifier())->simplify($points);

        self::assertLessThanOrEqual(RouteSimplifier::MAX_POINTS, \count($route));
        self::assertGreaterThan(1800, \count($route));
        self::assertSame(200000.0, $route[\count($route) - 1][2]);
    }
}
