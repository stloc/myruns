<?php

declare(strict_types=1);

namespace App\Training;

use App\Prediction\Goal;

final readonly class TrainingPlan
{
    /**
     * @param list<TrainingWeek> $weeks
     * @param list<string>       $notes
     */
    public function __construct(
        public Goal $goal,
        public TrainingPaces $paces,
        public int $sessionsPerWeek,
        public array $weeks,
        public array $notes,
        public ?int $clubWeekday = null,
        public ?string $clubTime = null,
    ) {
    }

    public function peakVolumeKm(): float
    {
        return max([0.0, ...array_map(static fn (TrainingWeek $week): float => $week->volumeKm(), $this->weeks)]);
    }

    public function longestRunKm(): float
    {
        $longest = 0.0;
        foreach ($this->weeks as $week) {
            foreach ($week->sessions as $session) {
                if (SessionType::Long === $session->type) {
                    $longest = max($longest, $session->distanceKm);
                }
            }
        }

        return $longest;
    }
}
