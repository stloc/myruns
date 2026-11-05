<?php

declare(strict_types=1);

namespace App\Effort;

/**
 * Allure ajustée à la pente (même principe que la NGP de Suunto / TrainingPeaks ou la GAP de Strava) :
 * chaque mètre compte selon son coût énergétique par rapport au plat, d'après Minetti et al. (2002,
 * « Energy cost of walking and running at extreme uphill and downhill slopes »).
 */
final class GradeAdjustment
{
    /** Pente mesurée sur cette distance centrée sur le point : l'altitude au mètre près est bruitée. */
    private const GRADE_WINDOW_METERS = 100.0;
    /** Domaine de validité de la formule de Minetti (±45 %). */
    private const MAX_GRADE = 0.45;
    /** Coût du plat (J/kg/m), terme constant de la formule. */
    private const FLAT_COST = 3.6;

    /**
     * Coût d'un mètre à cette pente rapporté à un mètre à plat (1 à plat, > 1 en montée, < 1 en
     * descente douce).
     */
    public static function costRatio(float $grade): float
    {
        $i = max(-self::MAX_GRADE, min(self::MAX_GRADE, $grade));

        return (155.4 * $i ** 5 - 30.4 * $i ** 4 - 43.3 * $i ** 3 + 46.3 * $i ** 2 + 19.5 * $i + self::FLAT_COST) / self::FLAT_COST;
    }

    /**
     * Pente (fraction) en chaque point, sur la fenêtre GRADE_WINDOW_METERS centrée sur lui ; null sans
     * altitude aux deux bords.
     *
     * @param list<array{0: float, 1: int, 2: ?float, 3: ?int, 4: ?float}> $points
     *
     * @return list<?float>
     */
    public static function grades(array $points): array
    {
        $count = \count($points);
        $grades = [];
        $from = 0;
        $to = 0;
        foreach ($points as $point) {
            while ($points[$from][0] < $point[0] - self::GRADE_WINDOW_METERS / 2) {
                ++$from;
            }
            while ($to < $count - 1 && $points[$to + 1][0] <= $point[0] + self::GRADE_WINDOW_METERS / 2) {
                ++$to;
            }
            $meters = $points[$to][0] - $points[$from][0];
            $grades[] = $meters > 0 && null !== $points[$to][2] && null !== $points[$from][2]
                ? ($points[$to][2] - $points[$from][2]) / $meters
                : null;
        }

        return $grades;
    }
}
