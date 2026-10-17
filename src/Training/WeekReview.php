<?php

declare(strict_types=1);

namespace App\Training;

use App\Entity\Activity;

final readonly class WeekReview
{
    /**
     * @param list<SessionReview> $sessions dans l'ordre des séances de la semaine
     * @param list<Activity>      $extras   sorties rattachées à aucune séance
     */
    public function __construct(
        public TrainingWeek $week,
        public array $sessions,
        public array $extras,
        public ?string $advice = null,
    ) {
    }

    public function doneKm(): float
    {
        return array_sum(array_map(static fn (SessionReview $review): float => $review->doneKm(), $this->sessions))
            + array_sum(array_map(static fn (Activity $run): float => $run->distanceMeters / 1000, $this->extras));
    }

    /** Séances faites à la bonne intensité, sur au moins 80 % de la distance. */
    public function succeededCount(): int
    {
        return \count(array_filter($this->sessions, static fn (SessionReview $review): bool => 'success' === $review->outcome->tone()));
    }
}
