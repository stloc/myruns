<?php

declare(strict_types=1);

namespace App\Training;

final readonly class PlanOptions
{
    /**
     * @param ?int                          $sessionsPerWeek sorties par semaine (null : selon la régularité)
     * @param array<string, WeekAdjustment> $adjustments     écarts par semaine, indexés par le lundi (Y-m-d)
     */
    public function __construct(
        public ?int $sessionsPerWeek = null,
        public array $adjustments = [],
    ) {
    }

    public function adjustmentFor(\DateTimeImmutable $monday): ?WeekAdjustment
    {
        return $this->adjustments[$monday->format('Y-m-d')] ?? null;
    }
}
