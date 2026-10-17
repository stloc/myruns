<?php

declare(strict_types=1);

namespace App\Import\Track;

/**
 * Point de mesure d'une trace, quel que soit son format (FIT, GPX, TCX).
 */
final readonly class TrackPoint
{
    public function __construct(
        public int $timestamp,
        /** Distance cumulée depuis le départ (m). */
        public float $distanceMeters,
        public ?int $heartRate = null,
        public ?float $altitudeMeters = null,
    ) {
    }
}
