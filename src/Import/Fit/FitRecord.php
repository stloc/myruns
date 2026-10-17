<?php

declare(strict_types=1);

namespace App\Import\Fit;

final readonly class FitRecord
{
    public function __construct(
        public int $timestamp,
        public ?float $distanceMeters,
        public ?int $heartRate,
        public ?float $altitudeMeters,
        public ?float $latitude,
        public ?float $longitude,
    ) {
    }
}
