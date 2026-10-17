<?php

declare(strict_types=1);

namespace App\Training;

use App\Entity\Activity;
use App\Enum\SplitKind;
use App\Enum\Sport;
use App\Prediction\HeartRateReserve;
use App\Util\Units;

/**
 * Compare une séance prévue aux sorties faites ce jour-là (règles déterministes).
 *
 * Faire plus long que prévu à la bonne intensité est positif : l'objectif de la séance est
 * largement atteint et les semaines suivantes en tiennent compte. Seule une intensité trop
 * élevée sur une séance facile, ou trop basse sur une séance de qualité, donne un avertissement.
 */
final class SessionEvaluator
{
    /** En dessous de 80 % de la distance prévue, la séance est incomplète. */
    public const PARTIAL_RATIO = 0.8;
    /** Au-delà de 120 %, l'objectif de la séance est largement atteint. */
    public const EXCEEDED_RATIO = 1.2;
    /** Marge sous l'allure de footing la plus rapide avant de juger une séance facile trop intense (s/km). */
    public const EASY_PACE_TOLERANCE = 15.0;
    /** Réserve cardiaque maximale d'une séance d'endurance (Daniels : E jusqu'à ≈ 79 %). */
    public const EASY_MAX_RESERVE = 0.80;
    /** Marge au-dessus de l'allure marathon pour reconnaître un passage de qualité (s/km). */
    public const QUALITY_PACE_TOLERANCE = 5.0;
    /** Longueur minimale d'un tour pour compter comme passage de qualité. */
    public const QUALITY_MIN_SPLIT_METERS = 200.0;

    private const EASY_TYPES = [SessionType::Easy, SessionType::Long, SessionType::Strides];
    /** Séances dont l'allure se vérifie sur les tours (côtes et club : contenu trop variable). */
    private const PACED_QUALITY_TYPES = [SessionType::Threshold, SessionType::Interval, SessionType::GoalPace];

    /**
     * @param list<Activity> $activities sorties rattachées à la séance
     */
    public function evaluate(TrainingSession $session, \DateTimeImmutable $date, array $activities, TrainingPaces $paces, ?HeartRateReserve $heartRate, \DateTimeImmutable $today): SessionReview
    {
        if ([] === $activities) {
            return $date->setTime(0, 0) < $today->setTime(0, 0)
                ? new SessionReview($session, $date, SessionOutcome::Missed, [], self::missedAdvice($session))
                : new SessionReview($session, $date, SessionOutcome::Upcoming);
        }

        $done = new SessionReview($session, $date, SessionOutcome::Done, $activities);
        $km = $done->doneKm();
        $pace = $done->pace();

        if (SessionType::Race === $session->type) {
            return new SessionReview($session, $date, SessionOutcome::Raced, $activities, \sprintf(
                'Course faite : %s km à %s, pour une allure objectif de %s.',
                self::km($km),
                Units::formatPace($pace),
                Units::formatPace($paces->goal),
            ));
        }

        if (\in_array($session->type, self::EASY_TYPES, true)) {
            $tooHard = $this->easyIntensityWarning($session, $activities, $pace, $paces, $heartRate);
            if (null !== $tooHard) {
                return new SessionReview($session, $date, SessionOutcome::TooHard, $activities, $tooHard);
            }
        } elseif (\in_array($session->type, self::PACED_QUALITY_TYPES, true) && false === self::hasQualityWork($activities, $paces)) {
            return new SessionReview($session, $date, SessionOutcome::TooEasy, $activities, \sprintf(
                'Aucun passage à %s ou plus vite : la séance a servi de footing. Marquez les fractions avec la touche Lap de la montre pour qu\'elles soient reconnues.',
                Units::formatPace($paces->marathon),
            ));
        }

        $ratio = $session->distanceKm > 0 ? $km / $session->distanceKm : 1.0;

        if ($ratio < self::PARTIAL_RATIO) {
            return new SessionReview($session, $date, SessionOutcome::Partial, $activities, \sprintf(
                'Séance écourtée : %s km sur %s prévus.%s',
                self::km($km),
                self::km($session->distanceKm),
                SessionType::Long === $session->type ? ' La sortie longue construit l\'endurance : visez la distance la prochaine fois, quitte à ralentir.' : '',
            ));
        }

        if ($ratio > self::EXCEEDED_RATIO) {
            return new SessionReview($session, $date, SessionOutcome::Exceeded, $activities, \in_array($session->type, self::EASY_TYPES, true)
                ? \sprintf('Objectif de la séance largement atteint : %s km en endurance au lieu de %s. Les semaines suivantes en tiennent compte.', self::km($km), self::km($session->distanceKm))
                : \sprintf('Séance faite, et même plus : %s km au lieu de %s. Récupérez bien avant la prochaine séance intense.', self::km($km), self::km($session->distanceKm)),
            );
        }

        return new SessionReview($session, $date, SessionOutcome::Done, $activities, \sprintf('Séance réussie : %s km à %s.', self::km($km), Units::formatPace($pace)));
    }

    /**
     * Avertissement si une séance facile a été courue trop vite. La FC, quand elle est connue, fait
     * foi : elle tient compte de la forme du jour, du terrain et de la chaleur ; sinon l'allure,
     * sauf en trail où le dénivelé la rend sans signification.
     *
     * @param list<Activity> $activities
     */
    private function easyIntensityWarning(TrainingSession $session, array $activities, ?float $pace, TrainingPaces $paces, ?HeartRateReserve $heartRate): ?string
    {
        $averageHeartRate = self::averageHeartRate($activities);
        if (null !== $heartRate && null !== $averageHeartRate) {
            $fraction = $heartRate->fraction($averageHeartRate);

            return $fraction > self::EASY_MAX_RESERVE ? \sprintf(
                '%s couru à %d %% de votre réserve cardiaque (FC moyenne %d) : en endurance, restez sous %d %%. Gardez l\'intensité pour les séances de qualité, vous progresserez davantage.',
                $session->type->label(),
                (int) round($fraction * 100),
                (int) round($averageHeartRate),
                (int) round(self::EASY_MAX_RESERVE * 100),
            ) : null;
        }

        $trail = [] === array_filter($activities, static fn (Activity $run): bool => Sport::TrailRun !== $run->sport);
        if ($trail || null === $pace || $pace >= $paces->easyFast - self::EASY_PACE_TOLERANCE) {
            return null;
        }

        return \sprintf(
            '%s couru à %s, plus vite que votre zone d\'endurance (%s–%s) : gardez l\'intensité pour les séances de qualité, vous progresserez davantage.',
            $session->type->label(),
            Units::formatPace($pace),
            Units::formatDuration($paces->easyFast),
            Units::formatPace($paces->easySlow),
        );
    }

    /**
     * Au moins un tour (à défaut un kilomètre) à l'allure marathon ou plus vite ; null si aucune
     * sortie n'a de temps de passage (on ne juge pas).
     *
     * @param list<Activity> $activities
     */
    private static function hasQualityWork(array $activities, TrainingPaces $paces): ?bool
    {
        $splits = [];
        foreach ($activities as $run) {
            $laps = $run->splitsOf(SplitKind::Lap);
            array_push($splits, ...([] !== $laps ? $laps : $run->splitsOf(SplitKind::Kilometer)));
        }
        if ([] === $splits) {
            return null;
        }

        foreach ($splits as $split) {
            $pace = $split->paceSecondsPerKm();
            if ($split->distanceMeters >= self::QUALITY_MIN_SPLIT_METERS && null !== $pace && $pace <= $paces->marathon + self::QUALITY_PACE_TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    /**
     * FC moyenne pondérée par la durée des sorties qui l'ont enregistrée.
     *
     * @param list<Activity> $activities
     */
    private static function averageHeartRate(array $activities): ?float
    {
        $beats = 0.0;
        $seconds = 0;
        foreach ($activities as $run) {
            if (null !== $run->averageHeartRate && $run->movingTimeSeconds > 0) {
                $beats += $run->averageHeartRate * $run->movingTimeSeconds;
                $seconds += $run->movingTimeSeconds;
            }
        }

        return $seconds > 0 ? $beats / $seconds : null;
    }

    private static function missedAdvice(TrainingSession $session): ?string
    {
        return match (true) {
            SessionType::Long === $session->type => 'Sortie longue manquée : c\'est la séance qui construit l\'endurance. Si possible, reportez-la dans la semaine plutôt que de la sauter, sans l\'enchaîner avec une séance intense.',
            SessionType::Race === $session->type => null,
            $session->type->isQuality(), SessionType::Hills === $session->type => 'Séance de qualité manquée : inutile de la rattraper en plus des autres, reprenez le programme normalement.',
            default => 'Footing manqué : pas besoin de le rattraper.',
        };
    }

    private static function km(float $km): string
    {
        return number_format($km, 1, ',', '');
    }
}
