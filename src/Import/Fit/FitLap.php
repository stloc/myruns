<?php

declare(strict_types=1);

namespace App\Import\Fit;

final readonly class FitLap
{
    /**
     * @param ?int $intensity 0 effort, 1 récupération, 2 échauffement, 3 retour au calme
     */
    public function __construct(
        public int $startTimestamp,
        public float $timerSeconds,
        public float $distanceMeters,
        public ?int $averageHeartRate,
        public ?int $maxHeartRate,
        public ?int $ascentMeters,
        public ?int $intensity,
    ) {
    }
}
