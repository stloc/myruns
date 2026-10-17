<?php

declare(strict_types=1);

namespace App\Route;

use App\Entity\Activity;

/**
 * Une activité face aux autres sorties du même parcours, comparées au temps de déplacement.
 */
final readonly class RouteComparison
{
    /** Lignes du tableau : les sorties les plus récentes (plus l'activité si elle est plus ancienne). */
    public const MAX_ROWS = 30;

    /**
     * @param list<Activity>                                                              $outings toutes les sorties du parcours (activité comprise), de la plus récente à la plus ancienne
     * @param list<array{activity: Activity, rank: int, gap_to_best: int, current: bool}> $rows
     */
    private function __construct(
        public Activity $activity,
        public array $outings,
        public int $rank,
        public Activity $best,
        public ?Activity $previous,
        public float $othersAverageSeconds,
        public ?float $othersAverageHeartRate,
        public array $rows,
    ) {
    }

    /**
     * @param list<Activity> $outings sorties du parcours, activité comprise, de la plus récente à la plus ancienne
     *
     * @return self|null null si l'activité est la seule sortie du parcours
     */
    public static function of(Activity $activity, array $outings): ?self
    {
        $others = array_values(array_filter($outings, static fn (Activity $a): bool => $a !== $activity));
        if ([] === $others) {
            return null;
        }

        $best = $activity;
        foreach ($others as $other) {
            if ($other->movingTimeSeconds < $best->movingTimeSeconds) {
                $best = $other;
            }
        }

        $previous = null;
        foreach ($others as $other) {
            if ($other->startDate < $activity->startDate) {
                $previous = $other; // la plus récente des précédentes : liste triée par date décroissante
                break;
            }
        }

        $heartRates = array_values(array_filter(array_map(static fn (Activity $a): ?float => $a->averageHeartRate, $others), static fn (?float $hr): bool => null !== $hr));

        $shown = \array_slice($outings, 0, self::MAX_ROWS);
        if (!\in_array($activity, $shown, true)) {
            $shown[] = $activity;
        }

        return new self(
            $activity,
            $outings,
            self::rankOf($activity, $outings),
            $best,
            $previous,
            array_sum(array_map(static fn (Activity $a): int => $a->movingTimeSeconds, $others)) / \count($others),
            [] === $heartRates ? null : array_sum($heartRates) / \count($heartRates),
            array_map(static fn (Activity $a): array => [
                'activity' => $a,
                'rank' => self::rankOf($a, $outings),
                'gap_to_best' => $a->movingTimeSeconds - $best->movingTimeSeconds,
                'current' => $a === $activity,
            ], $shown),
        );
    }

    public function count(): int
    {
        return \count($this->outings);
    }

    public function first(): Activity
    {
        return $this->outings[\count($this->outings) - 1];
    }

    /** Écart au meilleur temps en secondes (0 si l'activité est le record). */
    public function gapToBest(): int
    {
        return $this->activity->movingTimeSeconds - $this->best->movingTimeSeconds;
    }

    /** Écart à la moyenne des autres sorties en secondes (négatif = plus rapide). */
    public function gapToAverage(): float
    {
        return $this->activity->movingTimeSeconds - $this->othersAverageSeconds;
    }

    public function gapToPrevious(): ?int
    {
        return null === $this->previous ? null : $this->activity->movingTimeSeconds - $this->previous->movingTimeSeconds;
    }

    /**
     * Classement au temps de déplacement : 1 = le plus rapide, ex æquo au même rang.
     *
     * @param list<Activity> $outings
     */
    private static function rankOf(Activity $activity, array $outings): int
    {
        return 1 + \count(array_filter($outings, static fn (Activity $a): bool => $a->movingTimeSeconds < $activity->movingTimeSeconds));
    }
}
