<?php

declare(strict_types=1);

namespace App\Training;

use App\Entity\Activity;

final readonly class SessionReview
{
    /**
     * @param list<Activity> $activities sorties rattachées à la séance
     */
    public function __construct(
        public TrainingSession $session,
        public \DateTimeImmutable $date,
        public SessionOutcome $outcome,
        public array $activities = [],
        public ?string $advice = null,
    ) {
    }

    public function doneKm(): float
    {
        return array_sum(array_map(static fn (Activity $run): float => $run->distanceMeters / 1000, $this->activities));
    }

    /** Allure moyenne des sorties rattachées (s/km), null sans sortie. */
    public function pace(): ?float
    {
        $km = $this->doneKm();

        return $km > 0 ? array_sum(array_map(static fn (Activity $run): int => $run->movingTimeSeconds, $this->activities)) / $km : null;
    }

    public function contains(Activity $activity): bool
    {
        foreach ($this->activities as $run) {
            if ($run->id === $activity->id) {
                return true;
            }
        }

        return false;
    }
}
