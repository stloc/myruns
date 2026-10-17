<?php

declare(strict_types=1);

namespace App\Training;

enum Phase: string
{
    case Base = 'base';
    case Development = 'development';
    case Specific = 'specific';
    case Taper = 'taper';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Foncier',
            self::Development => 'Développement',
            self::Specific => 'Spécifique',
            self::Taper => 'Affûtage',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::Base => 'Construire l\'endurance : volume en aisance, quelques accélérations.',
            self::Development => 'Relever le seuil et la VMA, la sortie longue s\'allonge.',
            self::Specific => 'Travailler à l\'allure de l\'objectif.',
            self::Taper => 'Réduire le volume, garder un peu d\'intensité, arriver frais.',
        };
    }
}
