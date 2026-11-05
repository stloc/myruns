<?php

declare(strict_types=1);

namespace App\Training;

/**
 * Écart d'une semaine par rapport aux réglages généraux (null : réglage général).
 */
final readonly class WeekAdjustment
{
    /**
     * @param array<int, int>         $days  jour prévu (1 = lundi … 7) → jour choisi, seulement pour les jours déplacés
     * @param array<int, SessionEdit> $edits séances réécrites, par jour prévu
     */
    public function __construct(
        public ?int $sessions = null,
        public array $days = [],
        public array $edits = [],
    ) {
    }
}
