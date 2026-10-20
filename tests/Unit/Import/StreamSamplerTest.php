<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Track\StreamSampler;
use App\Import\Track\TrackPoint;
use PHPUnit\Framework\TestCase;

final class StreamSamplerTest extends TestCase
{
    public function testSamplesEveryTenMetersAndAveragesHeartRateAndCadence(): void
    {
        // 2,5 m/s pendant 100 s : un échantillon tous les 4 points, FC et cadence moyennées depuis le précédent.
        $points = [];
        for ($i = 0; $i <= 100; ++$i) {
            $points[] = new TrackPoint(1000 + $i, $i * 2.5, 140 + 2 * $i, 100 + $i / 10, cadence: 0 === $i % 2 ? 80.0 : 81.0);
        }

        $samples = (new StreamSampler())->sample($points);

        self::assertCount(26, $samples);
        self::assertSame([0.0, 0, 100.0, 140, 80.0], $samples[0]);
        self::assertSame([10.0, 4, 100.4, 145, 80.5], $samples[1]);
        self::assertSame([250.0, 100, 110.0, 337, 80.5], $samples[25]);
    }

    public function testLongActivityKeepsAtMostMaxPointsAndTheFinish(): void
    {
        $points = [];
        for ($i = 0; $i <= 10000; ++$i) {
            $points[] = new TrackPoint($i * 4, $i * 10.0);
        }

        $samples = (new StreamSampler())->sample($points);

        self::assertLessThanOrEqual(StreamSampler::MAX_POINTS, \count($samples));
        self::assertSame(100000.0, $samples[\count($samples) - 1][0]);
        self::assertSame([null, null, null], \array_slice($samples[1], 2), 'Ni altitude, ni FC, ni cadence');
    }

    public function testNoDistanceGivesNoSamples(): void
    {
        self::assertSame([], (new StreamSampler())->sample([new TrackPoint(0, 0.0, 120), new TrackPoint(60, 0.0, 125)]));
    }
}
