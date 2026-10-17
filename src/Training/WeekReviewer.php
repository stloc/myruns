<?php

declare(strict_types=1);

namespace App\Training;

use App\Entity\Activity;
use App\Prediction\HeartRateReserve;

/**
 * Rattache les sorties d'une semaine à ses séances, puis évalue chaque séance.
 *
 * 1. sorties du jour de la séance ;
 * 2. sinon, sorties encore libres d'un jour voisin (±1, dans la semaine) : séance décalée d'un
 *    jour. Seulement une fois le jour de la séance passé : avant, elle peut encore être faite ;
 * 3. les sorties restantes sont « hors programme ».
 */
final readonly class WeekReviewer
{
    /** Au-delà de 130 % du volume prévu, on conseille une semaine suivante calme. */
    public const OVERLOAD_RATIO = 1.3;

    public function __construct(
        private SessionEvaluator $evaluator = new SessionEvaluator(),
    ) {
    }

    /**
     * @param list<Activity> $runs sorties de course (au moins celles de la semaine)
     */
    public function review(TrainingWeek $week, array $runs, TrainingPaces $paces, ?HeartRateReserve $heartRate, \DateTimeImmutable $today): WeekReview
    {
        $byDay = [];
        foreach ($runs as $run) {
            $local = $run->startDate->setTimezone($today->getTimezone());
            if ($week->contains($local)) {
                $byDay[$local->format('Y-m-d')][] = $run;
            }
        }
        ksort($byDay);

        $dates = [];
        foreach ($week->sessions as $i => $session) {
            $dates[$i] = $week->dateOf($session) ?? $week->sunday();
        }

        $assigned = [];
        $used = [];
        foreach ($dates as $i => $date) {
            $day = $date->format('Y-m-d');
            if (isset($byDay[$day]) && !isset($used[$day])) {
                $assigned[$i] = $byDay[$day];
                $used[$day] = true;
            }
        }
        foreach ($dates as $i => $date) {
            if (isset($assigned[$i]) || $date >= $today->setTime(0, 0)) {
                continue;
            }
            foreach (['+1 day', '-1 day'] as $shift) {
                $day = $date->modify($shift)->format('Y-m-d');
                if (isset($byDay[$day]) && !isset($used[$day])) {
                    $assigned[$i] = $byDay[$day];
                    $used[$day] = true;
                    break;
                }
            }
        }

        $sessions = [];
        foreach ($week->sessions as $i => $session) {
            $sessions[] = $this->evaluator->evaluate($session, $dates[$i], $assigned[$i] ?? [], $paces, $heartRate, $today);
        }
        $extras = [];
        foreach ($byDay as $day => $dayRuns) {
            if (!isset($used[$day])) {
                array_push($extras, ...$dayRuns);
            }
        }

        $review = new WeekReview($week, $sessions, $extras);
        $planned = $week->volumeKm();
        if ($planned > 0 && $review->doneKm() > self::OVERLOAD_RATIO * $planned) {
            $review = new WeekReview($week, $sessions, $extras, \sprintf(
                'Semaine à %s km pour %s prévus : bien au-dessus du programme. Si la fatigue se fait sentir, gardez les footings de la semaine suivante vraiment faciles.',
                number_format($review->doneKm(), 1, ',', ''),
                number_format($planned, 1, ',', ''),
            ));
        }

        return $review;
    }
}
