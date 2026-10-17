<?php

declare(strict_types=1);

namespace App\Training;

use App\Prediction\Goal;
use App\Prediction\Vdot;

/**
 * Allures d'entraînement (s/km) déduites du VDOT, d'après les intensités de Daniels
 * (fraction de VO2max) ; l'allure objectif vient directement du temps visé.
 */
final readonly class TrainingPaces
{
    private const EASY_SLOW = 0.62;
    private const EASY_FAST = 0.70;
    private const THRESHOLD = 0.88;
    private const INTERVAL = 0.975;
    private const REPETITION = 1.06;

    public function __construct(
        public float $vdot,
        public float $easySlow,
        public float $easyFast,
        public float $marathon,
        public float $threshold,
        public float $interval,
        public float $repetition,
        public float $goal,
    ) {
    }

    public static function from(float $vdot, Goal $goal): self
    {
        return new self(
            vdot: $vdot,
            easySlow: self::paceAt($vdot, self::EASY_SLOW),
            easyFast: self::paceAt($vdot, self::EASY_FAST),
            marathon: Vdot::timeFor($vdot, 42195) / 42.195,
            threshold: self::paceAt($vdot, self::THRESHOLD),
            interval: self::paceAt($vdot, self::INTERVAL),
            repetition: self::paceAt($vdot, self::REPETITION),
            goal: $goal->targetSeconds / ($goal->distanceMeters / 1000),
        );
    }

    /** Allure moyenne des footings, utilisée pour estimer les distances. */
    public function easy(): float
    {
        return ($this->easySlow + $this->easyFast) / 2;
    }

    private static function paceAt(float $vdot, float $fraction): float
    {
        return 60000 / Vdot::velocityFor($vdot * $fraction);
    }
}
