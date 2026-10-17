<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Enum\Sport;
use App\Import\Fit\FitDecoder;
use App\Import\TrackFileParser;
use App\Tests\Fit\FitFileBuilder;
use PHPUnit\Framework\TestCase;

final class FitDecoderTest extends TestCase
{
    private const START = 1792000000;

    public function testDecodesRecordsLapsAndSport(): void
    {
        $builder = (new FitFileBuilder())->session(1);
        for ($i = 0; $i <= 600; ++$i) {
            // 3,5 m/s pendant 10 minutes ; un point sur deux en horodatage compressé.
            $builder->record(self::START + $i, $i * 3.5, 140 + intdiv($i, 60), 1 === $i % 2);
        }
        $builder->lap(self::START, 300, 1050, 142, 150)->lap(self::START + 300, 300, 1050, 147, 155);

        $fit = (new FitDecoder())->decode($builder->build());

        self::assertSame(1, $fit->sport);
        self::assertCount(601, $fit->records);
        self::assertSame(self::START + 599, $fit->records[599]->timestamp, 'Horodatage compressé reconstruit');
        self::assertSame(2096.5, $fit->records[599]->distanceMeters);
        self::assertSame(149, $fit->records[599]->heartRate);
        self::assertCount(2, $fit->laps);
        self::assertSame(300.0, $fit->laps[1]->timerSeconds);
        self::assertSame(155, $fit->laps[1]->maxHeartRate);
    }

    public function testTrackParserBuildsActivityFromGzippedFit(): void
    {
        $builder = (new FitFileBuilder())->session(1);
        for ($i = 0; $i <= 600; ++$i) {
            $builder->record(self::START + $i, $i * 3.5, 150);
        }

        $track = (new TrackFileParser())->parse('activities/42.fit.gz', (string) gzencode($builder->build()));

        self::assertNotNull($track);
        self::assertSame(Sport::Run, $track->activity->sport);
        self::assertSame(2100.0, $track->activity->distanceMeters);
        self::assertSame(600, $track->activity->movingTimeSeconds);
        self::assertSame(150.0, $track->activity->maxHeartRate);
    }

    public function testRejectsNonFitData(): void
    {
        self::assertFalse(FitDecoder::isFit('pas un fit'));
        $this->expectException(\InvalidArgumentException::class);
        (new FitDecoder())->decode('pas un fit');
    }
}
