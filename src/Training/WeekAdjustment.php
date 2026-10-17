<?php

declare(strict_types=1);

namespace App\Training;

/**
 * Écart d'une semaine par rapport aux réglages généraux (null : réglage général).
 */
final readonly class WeekAdjustment
{
    public function __construct(
        public ?bool $club = null,
        public ?int $sessions = null,
    ) {
    }
}
