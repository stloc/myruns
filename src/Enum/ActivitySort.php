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
            self::Date => 'Date',
            self::Distance => 'Distance',
            self::Duration => 'Durée',
            self::Elevation => 'Dénivelé',
            self::Pace => 'Allure',
        };
    }

    /**
     * Sens proposé quand aucun n'est demandé : la valeur la plus forte d'abord, sauf l'allure
     * (secondes par km : la plus rapide est la plus petite).
     */
    public function defaultDirection(): \SortDirection
    {
        return self::Pace === $this ? \SortDirection::Ascending : \SortDirection::Descending;
    }
}
