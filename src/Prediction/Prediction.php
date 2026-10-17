<?php

declare(strict_types=1);

namespace App\Prediction;

final readonly class Prediction
{
    /**
     * @param list<string> $factors explications lisibles des ajustements appliqués
     */
    public function __construct(
        public Goal $goal,
        public FitnessSnapshot $fitness,
        public int $probabilityPercent,
        public float $requiredVdot,
        public float $projectedVdot,
        public float $endurancePenalty,
        public float $predictedSecondsToday,
        public float $predictedSecondsOnGoalDate,
        public float $uncertainty,
        public int $weeksToGoal,
        public array $factors,
    ) {
    }

    public function confidenceLabel(): string
    {
        return match (true) {
            $this->uncertainty <= 1.5 => 'élevée',
            $this->uncertainty <= 2.5 => 'moyenne',
            default => 'faible',
        };
    }
}
