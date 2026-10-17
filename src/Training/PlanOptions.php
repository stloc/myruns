<?php

declare(strict_types=1);

namespace App\Training;

final readonly class PlanOptions
{
    /**
     * @param ?int                          $sessionsPerWeek sorties par semaine, séance club comprise (null : selon la régularité)
     * @param ?int                          $clubWeekday     jour de la séance club, 1 (lundi) à 7 (dimanche) ; null : pas de club
     * @param array<string, WeekAdjustment> $adjustments     écarts par semaine, indexés par le lundi (Y-m-d)
     */
    public function __construct(
        public ?int $sessionsPerWeek = null,
        public ?int $clubWeekday = null,
        public ?string $clubTime = null,
        public array $adjustments = [],
    ) {
    }

    public function adjustmentFor(\DateTimeImmutable $monday): ?WeekAdjustment
    {
        return $this->adjustments[$monday->format('Y-m-d')] ?? null;
    }
}
