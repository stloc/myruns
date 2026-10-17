<?php

declare(strict_types=1);

namespace App\Training;

use App\Prediction\GoalPredictor;
use App\Prediction\HeartRateReserve;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;

/**
 * Compare le programme aux sorties faites, sans rien stocker : le prévu d'une semaine est le
 * programme construit avec la forme du lundi (il ne bouge plus pendant la semaine), les semaines
 * suivantes sont recalculées avec la forme du jour (le programme suit les sorties faites).
 */
final readonly class PlanReviewer
{
    /** Écart en dessous duquel un changement de distance n'est pas signalé (arrondi des séances). */
    private const MIN_CHANGE_KM = 0.5;
    private const MIN_CHANGE_VDOT = 0.1;

    public function __construct(
        private CurrentPlan $plans,
        private GoalPredictor $predictor,
        private ActivityRepository $activities,
        private AthleteProfileRepository $profiles,
        private WeekReviewer $weeks,
    ) {
    }

    /**
     * Null sans objectif ou si l'objectif est passé.
     */
    public function review(\DateTimeImmutable $now = new \DateTimeImmutable()): ?PlanReview
    {
        $today = $now->setTime(0, 0);
        $monday = $today->modify('monday this week');
        $latest = $this->plans->planAt($now);
        $planned = $this->plans->planAt($monday) ?? $latest;
        $plannedWeek = $planned->weeks[0] ?? null;
        if (null === $latest || null === $planned || null === $plannedWeek) {
            return null;
        }

        $heartRate = HeartRateReserve::of($this->profiles->current());
        $runs = $this->activities->findRunsBetween($monday->modify('-1 week'), $monday->modify('+1 week'));
        $goal = $latest->goal;

        $current = $this->weeks->review($plannedWeek, $runs, $planned->paces, $heartRate, $today);

        $previous = null;
        $previousPlan = $this->plans->planAt($monday->modify('-1 week'));
        $previousWeek = $previousPlan->weeks[0] ?? null;
        if (null !== $previousPlan && null !== $previousWeek) {
            $previous = $this->weeks->review($previousWeek, $runs, $previousPlan->paces, $heartRate, $today);
        }

        return new PlanReview(
            plan: new TrainingPlan($latest->goal, $latest->paces, $latest->sessionsPerWeek, [$plannedWeek, ...\array_slice($latest->weeks, 1)], $latest->notes, $latest->clubWeekday, $latest->clubTime),
            currentWeek: $current,
            previousWeek: $previous,
            changes: $this->changes($planned, $latest),
            probabilityPercent: $this->predictor->predict($goal, $now)?->probabilityPercent,
            probabilityAtMonday: $this->predictor->predict($goal, $monday)?->probabilityPercent,
        );
    }

    /**
     * Ce que les sorties faites depuis lundi ont changé à la semaine prochaine.
     *
     * @return list<string>
     */
    private function changes(TrainingPlan $planned, TrainingPlan $latest): array
    {
        $changes = [];
        $before = $planned->weeks[1] ?? null;
        $after = $latest->weeks[1] ?? null;
        if (null !== $before && null !== $after) {
            if (abs($after->volumeKm() - $before->volumeKm()) >= self::MIN_CHANGE_KM) {
                $changes[] = \sprintf('Semaine prochaine : %s km au lieu de %s km.', self::km($after->volumeKm()), self::km($before->volumeKm()));
            }
            $longBefore = self::longRunKm($before);
            $longAfter = self::longRunKm($after);
            if (null !== $longBefore && null !== $longAfter && abs($longAfter - $longBefore) >= self::MIN_CHANGE_KM) {
                $changes[] = \sprintf('Sortie longue de la semaine prochaine : %s km au lieu de %s km.', self::km($longAfter), self::km($longBefore));
            }
        }
        if (abs($latest->paces->vdot - $planned->paces->vdot) >= self::MIN_CHANGE_VDOT) {
            $changes[] = \sprintf(
                'Allures recalculées sur votre forme du jour : indice %s au lieu de %s lundi.',
                number_format($latest->paces->vdot, 1, ',', ''),
                number_format($planned->paces->vdot, 1, ',', ''),
            );
        }

        return $changes;
    }

    private static function longRunKm(TrainingWeek $week): ?float
    {
        foreach ($week->sessions as $session) {
            if (SessionType::Long === $session->type) {
                return $session->distanceKm;
            }
        }

        return null;
    }

    private static function km(float $km): string
    {
        return number_format($km, 1, ',', '');
    }
}
