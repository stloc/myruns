<?php

declare(strict_types=1);

namespace App\Prediction;

/**
 * Formules VDOT de Jack Daniels (« Daniels' Running Formula ») : un indice de forme unique
 * qui relie toutes les performances (5 km, 10 km, semi, marathon…) entre elles.
 */
final class Vdot
{
    public static function fromPerformance(float $distanceMeters, float $seconds): float
    {
        $minutes = $seconds / 60;

        return self::oxygenCost($distanceMeters / $minutes) / self::sustainableFraction($minutes);
    }

    /**
     * Coût en oxygène (ml/kg/min) de la course à une vitesse donnée (m/min).
     */
    public static function oxygenCost(float $metersPerMinute): float
    {
        return -4.60 + 0.182258 * $metersPerMinute + 0.000104 * $metersPerMinute ** 2;
    }

    /**
     * Fraction de la VO2max qu'un coureur peut soutenir pendant la durée donnée (effort maximal).
     */
    /**
     * Vitesse (m/min) pour une consommation d'oxygène donnée : inverse de oxygenCost().
     */
    public static function velocityFor(float $oxygenCost): float
    {
        $a = 0.000104;
        $b = 0.182258;

        return (-$b + sqrt($b ** 2 + 4 * $a * ($oxygenCost + 4.60))) / (2 * $a);
    }

    public static function sustainableFraction(float $minutes): float
    {
        return 0.8 + 0.1894393 * exp(-0.012778 * $minutes) + 0.2989558 * exp(-0.1932605 * $minutes);
    }

    /**
     * Temps (en secondes) qu'un coureur de VDOT donné peut réaliser sur la distance, par dichotomie.
     */
    public static function timeFor(float $vdot, float $distanceMeters): float
    {
        $low = $distanceMeters / 1000 * 120;   // 2:00/km
        $high = $distanceMeters / 1000 * 1200; // 20:00/km

        for ($i = 0; $i < 60; ++$i) {
            $mid = ($low + $high) / 2;
            // VDOT décroissant avec le temps : trop lent → VDOT trop bas → il faut aller plus vite.
            if (self::fromPerformance($distanceMeters, $mid) > $vdot) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }
}
