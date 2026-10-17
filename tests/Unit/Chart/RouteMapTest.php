<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chart;

use App\Chart\RouteMap;
use PHPUnit\Framework\TestCase;

final class RouteMapTest extends TestCase
{
    public function testColorsSegmentsByPaceQuintiles(): void
    {
        // 10 tronçons de 100 m (points tous les 20 m), du plus rapide (20 s) au plus lent (38 s).
        $map = RouteMap::fromPoints($this->route(array_map(static fn (int $i): int => 20 + 2 * $i, range(0, 9))), pace: true);

        self::assertNotNull($map);
        self::assertCount(10, $map->segments);
        self::assertSame(['pace-1', 'pace-1', 'pace-2', 'pace-2', 'pace-3', 'pace-3', 'pace-4', 'pace-4', 'pace-5', 'pace-5'], array_column($map->segments, 'class'));
        self::assertSame('3:20/km', $map->segments[0]['label']);
        self::assertSame(['class' => 'pace-1', 'label' => '3:20/km – 3:40/km'], $map->legend[0]);
        self::assertCount(5, $map->legend);
        // Les tronçons se touchent : la fin de l'un est le début du suivant.
        self::assertSame($map->segments[0]['points'][5], $map->segments[1]['points'][0]);
        self::assertSame([[48.0, -4.0], [48.00899, -4.0]], $map->bounds);
    }

    public function testStopsAreExcludedFromThePace(): void
    {
        // 2e tronçon : 60 s de pause au milieu (la montre reste au même endroit).
        $points = [];
        $time = 0;
        foreach (range(0, 15) as $i) {
            if (8 === $i) {
                $time += 60;
            }
            $points[] = [48 + $i * 20 / 111195, -4.0, $i * 20.0, $time];
            $time += 6;
        }
        // Puis 100 m à 0,2 m/s : sous le seuil de mouvement, tronçon « Arrêt ».
        foreach (range(16, 20) as $i) {
            $time += 100;
            $points[] = [48 + $i * 20 / 111195, -4.0, $i * 20.0, $time];
        }

        $map = RouteMap::fromPoints($points, pace: true);

        self::assertNotNull($map);
        self::assertSame(['5:00/km', '5:00/km', '5:00/km', 'Arrêt'], array_column($map->segments, 'label'));
        self::assertSame('stop', $map->segments[3]['class']);
        self::assertSame(['class' => 'stop', 'label' => 'Arrêt'], $map->legend[\count($map->legend) - 1]);
    }

    public function testShowsSpeedOutsideRunningAndMergesShortLastSegment(): void
    {
        // 230 m à vélo à 10 m/s : 2 tronçons, les 30 derniers mètres rejoignent le 2e.
        $points = [];
        foreach (range(0, 23) as $i) {
            $points[] = [48 + $i * 10 / 111195, -4.0, $i * 10.0, $i];
        }

        $map = RouteMap::fromPoints($points, pace: false);

        self::assertNotNull($map);
        self::assertCount(2, $map->segments);
        self::assertCount(14, $map->segments[1]['points']);
        self::assertSame('36,0 km/h', $map->segments[0]['label']);
        self::assertSame('36,0 km/h – 36,0 km/h', $map->legend[0]['label']);
    }

    public function testNoMapWithoutRoute(): void
    {
        self::assertNull(RouteMap::fromPoints([], pace: true));
        self::assertNull(RouteMap::fromPoints([[48.0, -4.0, 0.0, 0]], pace: true));
    }

    /**
     * @param list<int> $secondsPer100m
     *
     * @return list<array{0: float, 1: float, 2: float, 3: int}>
     */
    private function route(array $secondsPer100m): array
    {
        $points = [[48.0, -4.0, 0.0, 0]];
        $time = 0.0;
        foreach ($secondsPer100m as $s => $seconds) {
            for ($step = 1; $step <= 5; ++$step) {
                $time += $seconds / 5;
                $distance = $s * 100 + $step * 20;
                $points[] = [round(48 + $distance / 111195, 5), -4.0, (float) $distance, (int) round($time)];
            }
        }

        return $points;
    }
}
