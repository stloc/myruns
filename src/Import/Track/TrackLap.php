<?php

declare(strict_types=1);

namespace App\Import\Track;

/**
 * Tour enregistré par la montre (FIT, TCX).
 */
final readonly class TrackLap
{
    public function __construct(
        public float $distanceMeters,
        public float $seconds,
        public ?int $averageHeartRate = null,
        public ?int $maxHeartRate = null,
        public ?float $elevationGain = null,
    ) {
    }
}
