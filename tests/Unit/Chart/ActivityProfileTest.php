<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chart;

use App\Chart\ActivityProfile;
use App\Entity\Activity;
use App\Enum\Sport;
use PHPUnit\Framework\TestCase;

final class ActivityProfileTest extends TestCase
{
    public function testRunningProfileExcludesStopsAndDropsMissingSeries(): void
    {
        $profile = ActivityProfile::fromActivity($this->activity(Sport::Run));

        self::assertNotNull($profile);
        self::assertSame(['pace', 'cadence', 'altitude'], array_column($profile->series, 'key'), 'Sans ceinture : pas de FC');
        self::assertCount(201, $profile->distances);
        self::assertSame(2.0, $profile->distances[200]);
        // 10 m toutes les 4 s, pause de 60 s à 1 km : l'allure reste 6:40/km partout.
        self::assertSame([400.0], array_values(array_unique($profile->series[0]['values'])));
        self::assertSame('6:40/km', $profile->series[0]['average']);
        // La montre compte les foulées : pas par minute = 2 × cadence.
        self::assertSame('170 ppm', $profile->series[1]['average']);
        self::assertSame('48 – 52 m', $profile->series[2]['average']);
        self::assertArrayNotHasKey('average', $profile->toArray()['series'][0]);
    }

    public function testRideShowsSpeedAndPedalCadence(): void
    {
        $profile = ActivityProfile::fromActivity($this->activity(Sport::Ride));

        self::assertNotNull($profile);
        self::assertSame('Vitesse', $profile->series[0]['label']);
        self::assertSame(9.0, $profile->series[0]['values'][100]);
        self::assertSame('9,0 km/h', $profile->series[0]['average']);
        self::assertSame('85 rpm', $profile->series[1]['average']);
    }

    public function testNoStreamGivesNoProfile(): void
    {
        self::assertNull(ActivityProfile::fromActivity(new Activity('x', 'track_file', '', Sport::Run, new \DateTimeImmutable(), 2000, 800, 860)));
    }

    private function activity(Sport $sport): Activity
    {
        $activity = new Activity('x', 'track_file', '', $sport, new \DateTimeImmutable(), 2000, 800, 860);
        $points = [];
        for ($i = 0; $i <= 200; ++$i) {
            $points[] = [$i * 10.0, $i * 4 + ($i > 100 ? 60 : 0), 50 + 2 * sin($i / 10), null, 0 === $i ? null : 85.0];
        }
        $activity->replaceTrackData([], null, streamPoints: $points);

        return $activity;
    }
}
