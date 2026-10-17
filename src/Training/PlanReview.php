<?php

declare(strict_types=1);

namespace App\Training;

use App\Entity\Activity;

/**
 * Bilan prévu / réalisé de la semaine en cours et de la précédente, et ce que les sorties de la
 * semaine ont changé au programme.
 */
final readonly class PlanReview
{
    /**
     * @param TrainingPlan $plan                programme affiché : semaine en cours telle que prévue lundi, semaines suivantes recalculées
     * @param list<string> $changes             évolutions du programme depuis lundi
     * @param ?int         $probabilityPercent  probabilité de réussir l'objectif aujourd'hui
     * @param ?int         $probabilityAtMonday probabilité lundi, avant les sorties de la semaine
     */
    public function __construct(
        public TrainingPlan $plan,
        public WeekReview $currentWeek,
        public ?WeekReview $previousWeek,
        public array $changes,
        public ?int $probabilityPercent,
        public ?int $probabilityAtMonday,
    ) {
    }

    public function probabilityChange(): ?int
    {
        return null === $this->probabilityPercent || null === $this->probabilityAtMonday ? null : $this->probabilityPercent - $this->probabilityAtMonday;
    }

    public function sessionFor(Activity $activity): ?SessionReview
    {
        foreach ($this->sessionReviews() as $review) {
            if ($review->contains($activity)) {
                return $review;
            }
        }

        return null;
    }

    /** Séance faite la plus récente (semaine en cours, sinon précédente). */
    public function latestDone(): ?SessionReview
    {
        $latest = null;
        foreach ($this->sessionReviews() as $review) {
            if ([] !== $review->activities && (null === $latest || $review->date >= $latest->date)) {
                $latest = $review;
            }
        }

        return $latest;
    }

    /**
     * @return list<SessionReview>
     */
    private function sessionReviews(): array
    {
        return [...$this->previousWeek->sessions ?? [], ...$this->currentWeek->sessions];
    }
}
