<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Import\Track\ParsedTrack;
use App\Import\Track\SplitCalculator;
use App\Import\Track\TrackLap;
use App\Import\Track\TrackPoint;
use PHPUnit\Framework\TestCase;

final class SplitCalculatorTest extends TestCase
{
    public function testKilometerSplitsExcludePauses(): void
    {
        $points = [];
        $distance = 0.0;
        $time = 0;
        // 1,5 km à 4 m/s (4:10/km), pause de 60 s, puis 1 km à 2,5 m/s (6:40/km).
        for ($i = 0; $i < 375; ++$i) {
            $points[] = new TrackPoint($time++, $distance, 150);
            $distance += 4.0;
        }
        $time += 60;
        for ($i = 0; $i <= 400; ++$i) {
            $points[] = new TrackPoint($time++, $distance, 160);
            $distance += 2.5;
        }

        $splits = (new SplitCalculator())->kilometers($points);

        self::assertCount(3, $splits);
        self::assertSame(1000.0, $splits[0]['distance']);
        self::assertEqualsWithDelta(250, $splits[0]['seconds'], 1);
        // 500 m à 4 m/s + 500 m à 2,5 m/s ; la pause de 60 s n'est pas comptée.
        self::assertEqualsWithDelta(125 + 200, $splits[1]['seconds'], 2);
        self::assertSame(SplitKind::Kilometer, $splits[2]['kind']);
        self::assertSame(160, $splits[2]['avg_hr']);
    }

    public function testDeviceLapsAreKeptOnlyWhenThereAreSeveral(): void
    {
        $points = [new TrackPoint(0, 0.0), new TrackPoint(300, 1000.0)];
        $activity = new Activity('x', 'track_file', '', Sport::Run, new \DateTimeImmutable(), 1000, 300, 300);
        $calculator = new SplitCalculator();

        $single = $calculator->splits(new ParsedTrack($activity, $points, [new TrackLap(1000, 300)]));
        $several = $calculator->splits(new ParsedTrack($activity, $points, [new TrackLap(500, 140, 150, 160), new TrackLap(500, 160)]));

        self::assertCount(1, $single);
        self::assertCount(3, $several);
        self::assertSame(SplitKind::Lap, $several[1]['kind']);
        self::assertSame(160, $several[1]['max_hr']);
    }

    public function testSustainedMaxHeartRateIgnoresSensorSpikes(): void
    {
        $points = [];
        for ($t = 0; $t < 120; ++$t) {
            $heartRate = match (true) {
                30 === $t => 205,               // pic isolé d'une seconde
                $t >= 60 && $t < 72 => 182,     // 12 s à 182
                default => 150,
            };
            $points[] = new TrackPoint($t, $t * 3.0, $heartRate);
        }

        self::assertSame(182, (new SplitCalculator())->sustainedMaxHeartRate($points));
    }
}
