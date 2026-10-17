<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ordre des résultats de la recherche d'activités.
 */
enum ActivitySort: string
{
    case Date = 'date';
    case Distance = 'distance';
    case Duration = 'duree';
    case Elevation = 'denivele';
    case Pace = 'allure';

    public function label(): string
    {
        return match ($this) {
            self::Date => 'Plus récentes',
            self::Distance => 'Plus longues',
            self::Duration => 'Plus longues en durée',
            self::Elevation => 'Plus de dénivelé',
            self::Pace => 'Allure la plus rapide',
        };
    }
}
