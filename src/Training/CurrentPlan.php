<?php

declare(strict_types=1);

namespace App\Training;

use App\Entity\TrainingWeekOverride;
use App\Prediction\FitnessAnalyzer;
use App\Repository\AthleteProfileRepository;
use App\Repository\TrainingWeekOverrideRepository;

/**
 * Programme du dernier objectif, avec les réglages du profil et les ajustements par semaine.
 */
final readonly class CurrentPlan
{
    public function __construct(
        private AthleteProfileRepository $profiles,
        private TrainingWeekOverrideRepository $overrides,
        private FitnessAnalyzer $analyzer,
        private TrainingPlanBuilder $builder,
    ) {
    }

    /**
     * Programme tel qu'il était construit à cette date (forme connue à cette date) ; null sans
     * objectif ou si l'objectif est passé.
     */
    public function planAt(\DateTimeImmutable $now): ?TrainingPlan
    {
        $profile = $this->profiles->current();
        $goal = $profile->lastGoal();
        if (null === $goal) {
            return null;
        }

        $adjustments = array_map(
            static fn (TrainingWeekOverride $override): WeekAdjustment => new WeekAdjustment($override->sessions, $override->days ?? [], self::edits($override)),
            $this->overrides->findSinceIndexedByWeek($now->setTime(0, 0)->modify('monday this week')),
        );

        return $this->builder->build($goal, $this->analyzer->analyze($now), new PlanOptions($profile->planSessionsPerWeek, $adjustments), $now);
    }

    /**
     * @return array<int, SessionEdit>
     */
    private static function edits(TrainingWeekOverride $override): array
    {
        $edits = [];
        foreach ($override->edits ?? [] as $day => $edit) {
            $type = SessionType::tryFrom($edit['type']);
            if (null !== $type) {
                $edits[$day] = new SessionEdit($type, $edit['description'], $edit['km']);
            }
        }

        return $edits;
    }
}
