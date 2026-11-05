<?php

declare(strict_types=1);

namespace App\Tests\Unit\Effort;

use App\Effort\EffortSummary;
use App\Effort\GradeAdjustment;
use App\Entity\Activity;
use App\Enum\Sport;
use App\Prediction\HeartRateReserve;
use PHPUnit\Framework\TestCase;

final class EffortSummaryTest extends TestCase
{
    public function testMinettiCostRatio(): void
    {
        self::assertEqualsWithDelta(1.0, GradeAdjustment::costRatio(0.0), 1e-9);
        // Minetti à +10 % : 5,968 J/kg/m contre 3,6 à plat ; à −10 % : 2,152.
        self::assertEqualsWithDelta(1.658, GradeAdjustment::costRatio(0.10), 0.001);
        self::assertEqualsWithDelta(0.598, GradeAdjustment::costRatio(-0.10), 0.001);
        self::assertSame(GradeAdjustment::costRatio(0.45), GradeAdjustment::costRatio(0.80), 'Pente bornée au domaine de la formule');
    }

    public function testFlatRunKeepsItsPace(): void
    {
        // 2 km à 5:00/km, un point toutes les 3 s, plat, 85 foulées/min.
        $effort = EffortSummary::fromActivity($this->steadyRun(static fn (float $d): float => 50.0, 2000));

        self::assertNotNull($effort);
        self::assertEqualsWithDelta(300.0, $effort->gradeAdjustedPace, 0.01);
        self::assertEqualsWithDelta(1.0, $effort->kilometerAdjustments[1], 1e-9);
        self::assertSame(0.0, $effort->descent);
        self::assertSame(0, $effort->climbingSeconds);
        self::assertSame(1700, $effort->steps, '85 foulées/min × 2 pas × 10 min');
        self::assertEqualsWithDelta(2000 / 1700, $effort->stepLength, 0.001);
        self::assertSame([], $effort->zones, 'Sans FC max : pas de zones');
    }

    public function testClimbMakesTheAdjustedPaceFasterAndDescentSlower(): void
    {
        // 1er km en montée à 5 %, 2e km en descente à 5 %.
        $effort = EffortSummary::fromActivity($this->steadyRun(static fn (float $d): float => $d <= 1000 ? 50 + 0.05 * $d : 150 - 0.05 * $d, 2000));

        self::assertNotNull($effort);
        $up = 1 / GradeAdjustment::costRatio(0.05);
        $down = 1 / GradeAdjustment::costRatio(-0.05);
        self::assertEqualsWithDelta($up, $effort->kilometerAdjustments[1], 0.02, 'Allure ajustée plus rapide en montée');
        self::assertEqualsWithDelta($down, $effort->kilometerAdjustments[2], 0.02, 'Plus lente en descente douce');
        self::assertEqualsWithDelta(100.0, $effort->highest, 0.5);
        self::assertEqualsWithDelta(50.0, $effort->descent, 1.0);
        self::assertGreaterThan(250, $effort->climbingSeconds);
        self::assertGreaterThan(250, $effort->descendingSeconds);
    }

    public function testBestPacesAndHeartRateZones(): void
    {
        // 10 min : 5 min à 6:00/km puis 5 min à 4:00/km ; FC 130 puis 175.
        $points = [];
        $distance = 0.0;
        for ($t = 0; $t <= 600; $t += 3) {
            $points[] = [$distance, $t, 50.0, $t <= 300 ? 130 : 175, 85.0];
            $distance += $t < 300 ? 3 / 360 * 1000 : 3 / 240 * 1000;
        }
        $activity = new Activity('run', 'track_file', 'Fractionné', Sport::Run, new \DateTimeImmutable('2026-10-01 07:00'), $distance, 600, 600);
        $activity->replaceTrackData([], null, streamPoints: $points);

        $effort = EffortSummary::fromActivity($activity, new HeartRateReserve(190, 50));

        self::assertNotNull($effort);
        self::assertSame(['1 min', '3 min', '5 min', '10 min'], array_column($effort->bestPaces, 'label'));
        self::assertEqualsWithDelta(240.0, $effort->bestPaces[0]['pace'], 0.5);
        self::assertEqualsWithDelta(240.0, $effort->bestPaces[2]['pace'], 0.5);
        // 130 bpm = 57 % de la réserve (zone 1), 175 bpm = 89 % (zone 4).
        $seconds = array_column($effort->zones, 'seconds', 'zone');
        self::assertSame([5 => 0, 4 => 300, 3 => 0, 2 => 0, 1 => 300], $seconds);
        self::assertSame(['zone' => 1, 'label' => 'Récupération', 'from' => null, 'to' => 133, 'seconds' => 300, 'percent' => 50.0], $effort->zones[4]);
    }

    public function testNoGradeAdjustmentWithoutAltitudeOrForCycling(): void
    {
        $effort = EffortSummary::fromActivity($this->steadyRun(static fn (float $d): ?float => null, 2000));
        self::assertNotNull($effort);
        self::assertNull($effort->gradeAdjustedPace);
        self::assertNull($effort->descent);

        $ride = new Activity('ride', 'track_file', 'Vélo', Sport::Ride, new \DateTimeImmutable('2026-10-01 07:00'), 1000, 120, 120);
        $ride->replaceTrackData([], null, streamPoints: [[0.0, 0, 10.0, null, 80.0], [500.0, 60, 30.0, null, 80.0], [1000.0, 120, 10.0, null, 80.0]]);
        $effort = EffortSummary::fromActivity($ride);
        self::assertNotNull($effort);
        self::assertNull($effort->gradeAdjustedPace);
        self::assertSame([], $effort->bestPaces);
        self::assertNull($effort->steps, 'À vélo, la cadence est en tours de pédale');
    }

    /**
     * Course à 5:00/km, un point toutes les 3 s.
     *
     * @param \Closure(float): ?float $altitude
     */
    private function steadyRun(\Closure $altitude, int $meters): Activity
    {
        $seconds = (int) ($meters * 0.3);
        $activity = new Activity('run', 'track_file', 'Footing', Sport::Run, new \DateTimeImmutable('2026-10-01 07:00'), $meters, $seconds, $seconds);
        $points = [];
        for ($t = 0; $t <= $seconds; $t += 3) {
            $points[] = [$t / 0.3, $t, $altitude($t / 0.3), null, 85.0];
        }
        $activity->replaceTrackData([], null, streamPoints: $points);

        return $activity;
    }
}
