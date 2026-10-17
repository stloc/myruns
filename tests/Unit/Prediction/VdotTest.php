<?php

declare(strict_types=1);

namespace App\Tests\Unit\Prediction;

use App\Prediction\GoalPredictor;
use App\Prediction\Vdot;
use PHPUnit\Framework\TestCase;

final class VdotTest extends TestCase
{
    public function testMatchesDanielsTables(): void
    {
        // Tables de Daniels : 5 km en 20:00 ≈ VDOT 49,8 ; marathon en 3:00:00 ≈ VDOT 53,5.
        self::assertEqualsWithDelta(49.8, Vdot::fromPerformance(5000, 20 * 60), 0.3);
        self::assertEqualsWithDelta(53.5, Vdot::fromPerformance(42195, 3 * 3600), 0.3);
    }

    public function testTimeForIsTheInverseOfFromPerformance(): void
    {
        $vdot = Vdot::fromPerformance(10000, 45 * 60);

        self::assertEqualsWithDelta(45 * 60, Vdot::timeFor($vdot, 10000), 1);
    }

    public function testNormalCdf(): void
    {
        self::assertEqualsWithDelta(0.5, GoalPredictor::normalCdf(0), 0.0001);
        self::assertEqualsWithDelta(0.8413, GoalPredictor::normalCdf(1), 0.001);
        self::assertEqualsWithDelta(0.0228, GoalPredictor::normalCdf(-2), 0.001);
    }
}
