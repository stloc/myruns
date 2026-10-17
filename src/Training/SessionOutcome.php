<?php

declare(strict_types=1);

namespace App\Training;

/**
 * Bilan d'une séance du programme comparée aux sorties réellement faites.
 */
enum SessionOutcome: string
{
    case Upcoming = 'upcoming';
    case Missed = 'missed';
    case Partial = 'partial';
    case Done = 'done';
    /** Plus longue que prévu à la bonne intensité : objectif de la séance largement atteint. */
    case Exceeded = 'exceeded';
    /** Séance facile courue trop vite (allure ou FC au-dessus de l'endurance). */
    case TooHard = 'too_hard';
    /** Séance de qualité sans aucun passage à l'allure visée. */
    case TooEasy = 'too_easy';
    case Raced = 'raced';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'À venir',
            self::Missed => 'Manquée',
            self::Partial => 'Incomplète',
            self::Done => 'Réussie',
            self::Exceeded => 'Largement réussie',
            self::TooHard => 'Trop intense',
            self::TooEasy => 'Faite en footing',
            self::Raced => 'Course faite',
        };
    }

    /**
     * Ton d'affichage : success, warning, error ou neutral.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Done, self::Exceeded, self::Raced => 'success',
            self::Partial, self::TooHard, self::TooEasy => 'warning',
            self::Missed => 'error',
            self::Upcoming => 'neutral',
        };
    }
}
