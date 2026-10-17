<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Enum\Sport;
use App\Import\TrackFileParser;
use PHPUnit\Framework\TestCase;

final class TrackFileParserTest extends TestCase
{
    public function testParsesGpxWithPause(): void
    {
        // 11 points espacés de 10 s et ~30 m (3 m/s) vers le nord, puis 60 s d'arrêt.
        $points = '';
        for ($i = 0; $i <= 10; ++$i) {
            $points .= \sprintf('<trkpt lat="%.6f" lon="-4.0"><ele>%d</ele><time>%s</time></trkpt>', 48 + $i * 30 / 111195, 10 + 3 * $i, gmdate('Y-m-d\TH:i:s\Z', 1767600000 + 10 * $i));
        }
        $points .= \sprintf('<trkpt lat="%.6f" lon="-4.0"><ele>40</ele><time>%s</time></trkpt>', 48 + 300 / 111195, gmdate('Y-m-d\TH:i:s\Z', 1767600000 + 160));
        $gpx = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><name>Footing</name><type>running</type><trkseg>'.$points.'</trkseg></trk></gpx>';

        $track = (new TrackFileParser())->parse('footing.gpx.gz', (string) gzencode($gpx));

        self::assertNotNull($track);
        $activity = $track->activity;
        self::assertCount(12, $track->points);
        self::assertSame('file:footing.gpx', $activity->id);
        self::assertSame(Sport::Run, $activity->sport);
        self::assertEqualsWithDelta(300, $activity->distanceMeters, 1);
        self::assertSame(100, $activity->movingTimeSeconds);
        self::assertSame(160, $activity->elapsedTimeSeconds);
        self::assertSame(30.0, $activity->elevationGain);
    }

    public function testParsesTcxLaps(): void
    {
        $tcx = <<<'XML'
            <?xml version="1.0"?>
            <TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
              <Activities><Activity Sport="Running"><Id>2026-01-05T07:00:00Z</Id>
                <Lap StartTime="2026-01-05T07:00:00Z"><TotalTimeSeconds>1500</TotalTimeSeconds><DistanceMeters>5000</DistanceMeters><AverageHeartRateBpm><Value>150</Value></AverageHeartRateBpm></Lap>
                <Lap StartTime="2026-01-05T07:25:00Z"><TotalTimeSeconds>1500</TotalTimeSeconds><DistanceMeters>5200</DistanceMeters><AverageHeartRateBpm><Value>160</Value></AverageHeartRateBpm></Lap>
              </Activity></Activities>
            </TrainingCenterDatabase>
            XML;

        $track = (new TrackFileParser())->parse('run.tcx', $tcx);

        self::assertNotNull($track);
        $activity = $track->activity;
        self::assertCount(2, $track->laps);
        self::assertSame(10200.0, $activity->distanceMeters);
        self::assertSame(3000, $activity->elapsedTimeSeconds);
        self::assertSame(155.0, $activity->averageHeartRate);
        self::assertSame('2026-01-05 07:00', $activity->startDate->format('Y-m-d H:i'));
    }

    public function testIgnoresInvalidFile(): void
    {
        self::assertNull((new TrackFileParser())->parse('broken.gpx', 'pas du xml'));
    }
}
