<?php

declare(strict_types=1);

namespace App\Effort;

use App\Elevation\ElevationCorrector;
use App\Entity\Activity;
use App\Enum\Sport;
use App\Prediction\HeartRateReserve;

/**
 * Analyse de l'effort sur la page détail, calculée à l'affichage depuis la série échantillonnée
 * (ActivityStream) : allure ajustée à la pente (sortie et km), meilleures allures, relief, foulée et
 * temps par zone cardiaque. Rien n'est stocké.
 */
final readonly class EffortSummary
{
    /** Même seuil que SplitCalculator : en dessous, la montre est considérée à l'arrêt. */
    private const MOVING_SPEED_THRESHOLD = 0.5;
    /** Un écart plus long entre deux points correspond à une pause du chronomètre. */
    private const MAX_GAP_SECONDS = 30;
    /**
     * Meilleures allures sur ces durées (s), comme les montres ; pas de 30 s : sur la série
     * échantillonnée, un saut du GPS suffit à la fausser.
     */
    private const BEST_PACE_WINDOWS = [60 => '1 min', 180 => '3 min', 300 => '5 min', 600 => '10 min', 1200 => '20 min'];
    /** Pente au-delà de laquelle on monte ou on descend (en deçà : plat). */
    private const CLIMB_GRADE = 0.01;
    /**
     * Zones cardiaques en part de la réserve (Karvonen) : début de chaque zone ; la zone 1 compte
     * aussi le temps sous sa borne (échauffement, arrêts).
     */
    private const ZONES = [1 => [0.5, 'Récupération'], 2 => [0.6, 'Endurance'], 3 => [0.7, 'Tempo'], 4 => [0.8, 'Seuil'], 5 => [0.9, 'VO2max']];

    /**
     * @param array<int, float>                                                                         $kilometerAdjustments allure ajustée / allure réelle, par n° de km (à partir de 1)
     * @param list<array{label: string, pace: float}>                                                   $bestPaces
     * @param list<array{zone: int, label: string, from: ?int, to: ?int, seconds: int, percent: float}> $zones
     */
    private function __construct(
        public ?float $gradeAdjustedPace,
        public array $kilometerAdjustments,
        public array $bestPaces,
        public ?float $descent,
        public ?float $highest,
        public ?float $lowest,
        public ?int $climbingSeconds,
        public ?int $descendingSeconds,
        public ?int $steps,
        public ?float $stepLength,
        public array $zones,
    ) {
    }

    public static function fromActivity(Activity $activity, ?HeartRateReserve $reserve = null): ?self
    {
        $points = $activity->streamPoints();
        if (\count($points) < 2) {
            return null;
        }

        $running = $activity->sport->isRunning();
        $grades = GradeAdjustment::grades($points);
        $hasAltitude = \count(array_filter($grades, static fn (?float $g): bool => null !== $g)) >= 2;

        $movingMeters = 0.0;
        $equivalentMeters = 0.0;
        /** @var array<int, array{0: float, 1: float}> $kilometers mètres réels, mètres équivalents à plat */
        $kilometers = [];
        $climbing = 0;
        $descending = 0;
        $steps = 0.0;
        $cadenceSeconds = 0;
        $movingSeconds = 0;
        $countsSteps = $running || \in_array($activity->sport, [Sport::Walk, Sport::Hike], true);

        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            [$a, $b] = [$points[$i - 1], $points[$i]];
            $dt = $b[1] - $a[1];
            $dd = $b[0] - $a[0];
            if ($dt <= 0 || $dt > self::MAX_GAP_SECONDS || $dd / $dt < self::MOVING_SPEED_THRESHOLD) {
                continue;
            }
            $movingSeconds += $dt;

            $grade = null === $grades[$i - 1] || null === $grades[$i] ? null : ($grades[$i - 1] + $grades[$i]) / 2;
            $equivalent = $dd * (null === $grade ? 1.0 : GradeAdjustment::costRatio($grade));
            $movingMeters += $dd;
            $equivalentMeters += $equivalent;
            $km = (int) floor(($a[0] + $b[0]) / 2 / 1000) + 1;
            $kilometers[$km] = [($kilometers[$km][0] ?? 0.0) + $dd, ($kilometers[$km][1] ?? 0.0) + $equivalent];

            if (null !== $grade && $grade >= self::CLIMB_GRADE) {
                $climbing += $dt;
            } elseif (null !== $grade && $grade <= -self::CLIMB_GRADE) {
                $descending += $dt;
            }

            if ($countsSteps && null !== $a[4] && null !== $b[4]) {
                // Cadence en cycles/min (une foulée = deux pas).
                $steps += ($a[4] + $b[4]) * $dt / 60;
                $cadenceSeconds += $dt;
            }
        }

        $pace = $activity->paceSecondsPerKm();
        $adjust = $running && $hasAltitude && $equivalentMeters > 0;
        $altitudes = array_values(array_filter(array_column($points, 2), static fn (?float $a): bool => null !== $a));
        // Foulée seulement si la cadence couvre l'essentiel de la sortie (sinon le total de pas est faux).
        $hasSteps = $cadenceSeconds > 0 && $cadenceSeconds >= 0.9 * $movingSeconds && $steps >= 1;

        return new self(
            gradeAdjustedPace: $adjust && null !== $pace ? $pace * $movingMeters / $equivalentMeters : null,
            kilometerAdjustments: $adjust ? array_map(static fn (array $k): float => $k[1] > 0 ? $k[0] / $k[1] : 1.0, $kilometers) : [],
            bestPaces: $running ? self::bestPaces($points) : [],
            descent: $hasAltitude ? ElevationCorrector::gain(array_reverse($altitudes)) : null,
            highest: [] === $altitudes ? null : max($altitudes),
            lowest: [] === $altitudes ? null : min($altitudes),
            climbingSeconds: $hasAltitude ? $climbing : null,
            descendingSeconds: $hasAltitude ? $descending : null,
            steps: $hasSteps ? (int) round($steps) : null,
            stepLength: $hasSteps ? $movingMeters / $steps : null,
            zones: null === $reserve ? [] : self::zones($points, $reserve),
        );
    }

    /**
     * Distance maximale couverte sur chaque durée (fenêtre glissante, distance interpolée en fin de
     * fenêtre). Une durée n'est retenue que si la série a au moins trois points par fenêtre en moyenne.
     *
     * @param list<array{0: float, 1: int, 2: ?float, 3: ?int, 4: ?float}> $points
     *
     * @return list<array{label: string, pace: float}>
     */
    private static function bestPaces(array $points): array
    {
        $count = \count($points);
        $duration = $points[$count - 1][1] - $points[0][1];
        $interval = $duration / ($count - 1);
        $best = [];
        foreach (self::BEST_PACE_WINDOWS as $window => $label) {
            if ($window > $duration || $interval > $window / 3) {
                continue;
            }
            $meters = 0.0;
            $end = 0;
            foreach ($points as $start) {
                $until = $start[1] + $window;
                while ($end < $count - 1 && $points[$end][1] < $until) {
                    ++$end;
                }
                if ($points[$end][1] < $until) {
                    break;
                }
                $previous = $points[$end - 1];
                $ratio = ($until - $previous[1]) / ($points[$end][1] - $previous[1]);
                $meters = max($meters, $previous[0] + $ratio * ($points[$end][0] - $previous[0]) - $start[0]);
            }
            if ($meters > 0) {
                $best[] = ['label' => $label, 'pace' => $window / $meters * 1000];
            }
        }

        return $best;
    }

    /**
     * Temps passé dans chaque zone cardiaque (arrêts compris, pauses du chronomètre exclues).
     *
     * @param list<array{0: float, 1: int, 2: ?float, 3: ?int, 4: ?float}> $points
     *
     * @return list<array{zone: int, label: string, from: ?int, to: ?int, seconds: int, percent: float}>
     */
    private static function zones(array $points, HeartRateReserve $reserve): array
    {
        $seconds = array_fill_keys(array_keys(self::ZONES), 0);
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $dt = $points[$i][1] - $points[$i - 1][1];
            $heartRate = $points[$i][3];
            if (null === $heartRate || $dt <= 0 || $dt > self::MAX_GAP_SECONDS) {
                continue;
            }
            $fraction = $reserve->fraction($heartRate);
            $zone = 1;
            foreach (self::ZONES as $number => [$start]) {
                if ($fraction >= $start) {
                    $zone = $number;
                }
            }
            $seconds[$zone] += $dt;
        }

        $total = array_sum($seconds);
        if (0 === $total) {
            return [];
        }

        $bpm = static fn (float $fraction): int => (int) round($reserve->resting + $fraction * ($reserve->max - $reserve->resting));
        $zones = [];
        foreach (self::ZONES as $number => [$start, $label]) {
            $next = self::ZONES[$number + 1][0] ?? null;
            $zones[] = [
                'zone' => $number,
                'label' => $label,
                'from' => 1 === $number ? null : $bpm($start),
                'to' => null === $next ? null : $bpm($next) - 1,
                'seconds' => $seconds[$number],
                'percent' => 100.0 * $seconds[$number] / $total,
            ];
        }

        return array_reverse($zones);
    }
}
