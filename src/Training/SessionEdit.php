<?php

declare(strict_types=1);

namespace App\Training;

/**
 * Séance réécrite par l'utilisateur (contenu donné le jour même par le club, par exemple).
 */
final readonly class SessionEdit
{
    public function __construct(
        public SessionType $type,
        public string $description,
        public float $distanceKm,
    ) {
    }
}
