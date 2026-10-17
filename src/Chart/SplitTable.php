<?php

declare(strict_types=1);

namespace App\Chart;

use App\Entity\ActivitySplit;

/**
 * Lignes d'un tableau de temps de passage : allure, barre de vitesse relative (100 % = tronçon
 * le plus rapide) et repérage des tronçons nettement plus rapides que la médiane (fractionné).
 */
final readonly class SplitTable
{
    /** Tronçon « rapide » : allure au moins 8 % plus rapide que l'allure médiane. */
    private const FAST_RATIO = 0.92;

    /**
     * @param list<array{split: ActivitySplit, pace: ?float, cumulative: float, speed_percent: int, fast: bool}> $rows
     */
    private function __construct(public array $rows)
    {
    }

    /**
     * @param list<ActivitySplit> $splits
     */
    public static function fromSplits(array $splits): self
    {
        $paces = array_values(array_filter(array_map(
            // Le dernier tronçon, souvent incomplet, n'entre pas dans la médiane s'il fait moins de 500 m.
            static fn (ActivitySplit $s): ?float => $s->distanceMeters >= 500 ? $s->paceSecondsPerKm() : null,
            $splits,
        )));
        sort($paces);
        $median = [] === $paces ? null : $paces[intdiv(\count($paces), 2)];
        $best = $paces[0] ?? null;

        $rows = [];
        $cumulative = 0.0;
        foreach ($splits as $split) {
            $pace = $split->paceSecondsPerKm();
            $cumulative += $split->seconds;
            $rows[] = [
                'split' => $split,
                'pace' => $pace,
                'cumulative' => $cumulative,
                'speed_percent' => null !== $pace && null !== $best && $pace > 0 ? min(100, (int) round(100 * $best / $pace)) : 0,
                'fast' => null !== $pace && null !== $median && $split->distanceMeters >= 500 && $pace <= self::FAST_RATIO * $median,
            ];
        }

        return new self($rows);
    }

    /**
     * Les tours de la montre ne sont qu'un découpage automatique au km (déjà affiché) ?
     *
     * @param list<ActivitySplit> $laps
     */
    public static function areAutomaticKilometers(array $laps): bool
    {
        foreach (\array_slice($laps, 0, -1) as $lap) {
            if (abs($lap->distanceMeters - 1000) > 10) {
                return false;
            }
        }

        return true;
    }
}
