<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Enum\Sport;
use App\Import\GarminExportParser;
use App\Repository\ActivityRepository;
use PHPUnit\Framework\TestCase;

final class GarminExportParserTest extends TestCase
{
    public function testConvertsGarminUnits(): void
    {
        // Format de l'export : distances et D+ en cm, durées et dates en ms.
        $json = <<<'JSON'
            [{"summarizedActivitiesExport": [
                {"activityId": 123456789, "name": "Footing du dimanche", "activityType": "running",
                 "beginTimestamp": 1572785506000, "startTimeGmt": 1572785506000.0, "startTimeLocal": 1572789106000.0,
                 "duration": 3041271.97265625, "elapsedDuration": 3041271.97265625, "movingDuration": 3040381.000518799,
                 "distance": 1009599.0234375, "elevationGain": 4430.074620246887, "avgHr": 158.0, "maxHr": 176.0}
            ]}]
            JSON;

        $activities = iterator_to_array((new GarminExportParser())->parse($json), false);

        self::assertCount(1, $activities);
        $activity = $activities[0];
        self::assertSame('garmin:123456789', $activity->id);
        self::assertSame(ActivityRepository::SOURCE_GARMIN, $activity->source);
        self::assertSame('Footing du dimanche', $activity->name);
        self::assertSame(Sport::Run, $activity->sport);
        self::assertSame('2019-11-03 12:51:46', $activity->startDate->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $activity->startDate->getTimezone()->getName());
        self::assertSame(10096.0, $activity->distanceMeters);
        self::assertSame(3040, $activity->movingTimeSeconds);
        self::assertSame(3041, $activity->elapsedTimeSeconds);
        self::assertSame(44.0, $activity->elevationGain);
        self::assertSame(158.0, $activity->averageHeartRate);
        self::assertSame(176.0, $activity->maxHeartRate);
        self::assertNull($activity->trackFile);
    }

    public function testOldActivitiesWithoutNameOrMovingTime(): void
    {
        // Avant 2016, Garmin n'exporte ni nom ni temps en mouvement (movingDuration à 0 ou absent).
        $json = <<<'JSON'
            [{"summarizedActivitiesExport": [
                {"activityId": 1, "activityType": "trail_running", "startTimeGmt": 1393750870000.0,
                 "duration": 3825950.0, "movingDuration": 0.0, "distance": 1070773.0},
                {"activityId": 2, "activityType": "hiking", "startTimeGmt": 1336652082000.0, "duration": 1599360.0, "distance": 232399.0},
                {"activityId": 3, "activityType": "mountain_biking", "startTimeGmt": 1336652082000.0, "duration": 1000.0},
                {"activityId": 4, "activityType": "running", "name": "Trail de la côte", "startTimeGmt": 1336652082000.0, "duration": 1000.0},
                {"name": "sans identifiant", "startTimeGmt": 1336652082000.0}
            ]}]
            JSON;

        $activities = iterator_to_array((new GarminExportParser())->parse($json), false);

        self::assertCount(4, $activities);
        self::assertSame('', $activities[0]->name);
        self::assertSame(Sport::TrailRun, $activities[0]->sport);
        self::assertSame(3826, $activities[0]->movingTimeSeconds, 'Temps en mouvement inconnu : durée totale');
        self::assertNull($activities[0]->elevationGain);
        self::assertNull($activities[0]->averageHeartRate);
        self::assertSame(Sport::Hike, $activities[1]->sport);
        self::assertSame(Sport::Ride, $activities[2]->sport);
        self::assertSame(0.0, $activities[2]->distanceMeters);
        self::assertSame(Sport::TrailRun, $activities[3]->sport, 'Trail déclaré par le nom');
    }

    public function testSupportsOnlyTheSummaryFile(): void
    {
        self::assertTrue(GarminExportParser::supports('DI_CONNECT/DI-Connect-Fitness/laulo84_1_summarizedActivities.json'));
        self::assertFalse(GarminExportParser::supports('DI_CONNECT/DI-Connect-Fitness/laulo84_personalRecord.json'));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);

        iterator_to_array((new GarminExportParser())->parse('{'));
    }
}
