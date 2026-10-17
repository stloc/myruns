<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;
use App\Repository\RaceResultRepository;

/**
 * Estime le niveau actuel du coureur (VDOT) en combinant trois sources :
 *
 * 1. les meilleures performances à l'entraînement (pénalisées par leur ancienneté) ;
 * 2. si la FC max est connue, l'intensité cardiaque de chaque sortie : un footing à 70 % de la
 *    réserve cardiaque révèle un potentiel bien supérieur à son allure ;
 * 3. les courses officielles saisies dans le profil (efforts maximaux).
 */
final class FitnessAnalyzer
{
    private const WINDOW_WEEKS = 12;
    private const RACE_WINDOW_WEEKS = 26;
    /** Une course plus récente que ça sert de référence : l'estimation cardiaque est alors ignorée. */
    private const RACE_ANCHOR_WEEKS = 12;
    private const HEART_RATE_WINDOW_WEEKS = 6;
    private const MIN_DISTANCE_M = 3000.0;
    private const MIN_DURATION_S = 12 * 60;
    /** Durée minimale pour que la FC moyenne soit stabilisée. */
    private const MIN_HEART_RATE_DURATION_S = 20 * 60;
    private const MIN_HEART_RATE_RUNS = 3;
    /** Perte de VDOT par jour d'ancienneté au-delà de 14 jours (≈ 0,6 point par mois). */
    private const AGE_PENALTY_PER_DAY = 0.02;
    /** Mètres de plat équivalents à 1 m de dénivelé positif. */
    private const ELEVATION_EQUIVALENT = 6.0;
    private const TOP_EFFORTS = 3;
    /** Part de l'écart cardio/performances retenue : l'estimation cardiaque est bruitée. */
    private const HEART_RATE_TRUST = 0.7;
    private const HEART_RATE_MAX_GAIN = 8.0;

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly AthleteProfileRepository $profiles,
        private readonly RaceResultRepository $races,
    ) {
    }

    public function analyze(\DateTimeImmutable $now = new \DateTimeImmutable()): FitnessSnapshot
    {
        $profile = $this->profiles->current();
        $since = $now->modify(\sprintf('-%d weeks', self::WINDOW_WEEKS));
        $runs = array_values(array_filter(
            $this->activities->findRunsSince($since),
            static fn (Activity $run): bool => $run->startDate <= $now,
        ));

        // 1. Performances à l'entraînement.
        $scored = [];
        foreach ($runs as $run) {
            $vdot = $this->vdotOf($run);
            if (null !== $vdot) {
                $scored[] = ['run' => $run, 'vdot' => $vdot, 'score' => $vdot - $this->agePenalty($run->startDate, $now)];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = \array_slice($scored, 0, self::TOP_EFFORTS);
        $performanceVdot = [] === $top ? null : array_sum(array_column($top, 'score')) / \count($top);

        // 2. Intensité cardiaque.
        $heartRateEstimates = [];
        $reserve = HeartRateReserve::of($profile);
        if (null !== $reserve) {
            $hrSince = $now->modify(\sprintf('-%d weeks', self::HEART_RATE_WINDOW_WEEKS));
            foreach ($runs as $run) {
                $estimate = $run->startDate >= $hrSince ? $this->heartRateVdotOf($run, $reserve) : null;
                if (null !== $estimate) {
                    $heartRateEstimates[] = $estimate;
                }
            }
        }
        $heartRateVdot = \count($heartRateEstimates) >= self::MIN_HEART_RATE_RUNS ? self::median($heartRateEstimates) : null;

        // 3. Courses officielles.
        $bestRace = null;
        $raceVdot = null;
        foreach ($this->races->findBetween($now->modify(\sprintf('-%d weeks', self::RACE_WINDOW_WEEKS)), $now) as $race) {
            $score = $race->vdot() - $this->agePenalty($race->date, $now);
            if (null === $raceVdot || $score > $raceVdot) {
                $raceVdot = $score;
                $bestRace = $race;
            }
        }

        $raceIsRecent = null !== $bestRace && $bestRace->date >= $now->modify(\sprintf('-%d weeks', self::RACE_ANCHOR_WEEKS));
        $vdot = $this->combine($performanceVdot, $raceIsRecent ? null : $heartRateVdot, $raceVdot);

        $weeklyKm = array_fill(0, self::WINDOW_WEEKS, 0.0);
        $weeklyBestVdot = [];
        $runsLast4Weeks = 0;
        $longest = 0.0;
        foreach ($runs as $run) {
            $weeksAgo = intdiv((int) $run->startDate->diff($now)->days, 7);
            if ($weeksAgo >= self::WINDOW_WEEKS) {
                continue;
            }
            $index = self::WINDOW_WEEKS - 1 - $weeksAgo;
            $weeklyKm[$index] += $run->distanceMeters / 1000;
            if ($weeksAgo < 4) {
                ++$runsLast4Weeks;
            }
            if ($weeksAgo < 8) {
                $longest = max($longest, $run->distanceMeters / 1000);
            }
        }
        foreach ($scored as $item) {
            $index = self::WINDOW_WEEKS - 1 - intdiv((int) $item['run']->startDate->diff($now)->days, 7);
            $weeklyBestVdot[$index] = max($weeklyBestVdot[$index] ?? 0.0, $item['vdot']);
        }

        return new FitnessSnapshot(
            date: $now,
            vdot: null === $vdot ? null : round($vdot, 2),
            vdotTrendPerWeek: round($this->slope($weeklyBestVdot), 3),
            qualifyingRuns: \count($scored),
            runsLast4Weeks: $runsLast4Weeks,
            kmPerWeekLast4Weeks: round(array_sum(\array_slice($weeklyKm, -4)) / 4, 1),
            kmPerWeekLast12Weeks: round(array_sum($weeklyKm) / self::WINDOW_WEEKS, 1),
            longestRunKmLast8Weeks: round($longest, 1),
            weeklyKm: array_values(array_map(static fn (float $km): float => round($km, 1), $weeklyKm)),
            topEfforts: array_column($top, 'run'),
            lastRunDate: $runs[0]->startDate ?? null,
            performanceVdot: null === $performanceVdot ? null : round($performanceVdot, 2),
            heartRateVdot: null === $heartRateVdot ? null : round($heartRateVdot, 2),
            heartRateRuns: \count($heartRateEstimates),
            raceVdot: null === $raceVdot ? null : round($raceVdot, 2),
            bestRace: $bestRace,
            heartRateUsed: null !== $heartRateVdot && !$raceIsRecent,
        );
    }

    /**
     * VDOT retenu : les performances, rehaussées par l'estimation cardiaque (en partie, plafonnée),
     * et jamais en dessous d'une course officielle. Une course de moins de 12 semaines fait foi :
     * l'estimation cardiaque, très sensible à la FC de repos, n'est alors pas utilisée.
     */
    private function combine(?float $performance, ?float $heartRate, ?float $race): ?float
    {
        $vdot = $performance;
        if (null !== $heartRate) {
            $vdot = null === $vdot
                ? $heartRate
                : $vdot + min(self::HEART_RATE_MAX_GAIN, max(0.0, self::HEART_RATE_TRUST * ($heartRate - $vdot)));
        }
        if (null !== $race) {
            $vdot = null === $vdot ? $race : max($vdot, $race);
        }

        return $vdot;
    }

    private function vdotOf(Activity $run): ?float
    {
        if (Sport::TrailRun === $run->sport || $run->distanceMeters < self::MIN_DISTANCE_M || $run->movingTimeSeconds < self::MIN_DURATION_S) {
            return null;
        }

        $vdot = Vdot::fromPerformance($this->equivalentDistance($run), $run->movingTimeSeconds);

        // Valeurs aberrantes (GPS fou, activité mal typée) : hors des bornes humaines plausibles.
        return $vdot >= 15 && $vdot <= 85 ? $vdot : null;
    }

    /**
     * VO2max estimée à partir de la FC moyenne : la fraction de réserve cardiaque utilisée
     * (Karvonen) correspond à la fraction de réserve de VO2 (Swain).
     */
    private function heartRateVdotOf(Activity $run, HeartRateReserve $reserve): ?float
    {
        $heartRate = $run->averageHeartRate;
        if (null === $heartRate || Sport::TrailRun === $run->sport
            || $run->movingTimeSeconds < self::MIN_HEART_RATE_DURATION_S || $run->distanceMeters < self::MIN_DISTANCE_M) {
            return null;
        }

        $vo2 = Vdot::oxygenCost($this->equivalentDistance($run) / ($run->movingTimeSeconds / 60));
        $fraction = $reserve->fraction($heartRate);
        $estimate = 3.5 + ($vo2 - 3.5) / max($fraction, 0.01);

        // En dehors de 55-95 %, la relation FC/VO2 n'est plus fiable (FC trop basse ou effort maximal).
        if ($fraction < 0.55 || $fraction > 0.95) {
            return null;
        }

        return $estimate >= 15 && $estimate <= 85 ? $estimate : null;
    }

    private function equivalentDistance(Activity $run): float
    {
        return $run->distanceMeters + self::ELEVATION_EQUIVALENT * ($run->effectiveElevationGain() ?? 0.0);
    }

    private function agePenalty(\DateTimeImmutable $date, \DateTimeImmutable $now): float
    {
        return self::AGE_PENALTY_PER_DAY * max(0, (int) $date->diff($now)->days - 14);
    }

    /**
     * @param non-empty-list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }

    /**
     * Pente (régression linéaire) du meilleur VDOT hebdomadaire, en points par semaine.
     *
     * @param array<int, float> $points index de semaine → VDOT
     */
    private function slope(array $points): float
    {
        $n = \count($points);
        if ($n < 3) {
            return 0.0;
        }

        $meanX = array_sum(array_keys($points)) / $n;
        $meanY = array_sum($points) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($points as $x => $y) {
            $num += ($x - $meanX) * ($y - $meanY);
            $den += ($x - $meanX) ** 2;
        }

        return $den > 0 ? $num / $den : 0.0;
    }
}
