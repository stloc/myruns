<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Util\Units;

final readonly class Goal
{
    public function __construct(
        public float $distanceMeters,
        public int $targetSeconds,
        public \DateTimeImmutable $date,
    ) {
    }

    public function describe(): string
    {
        return \sprintf('%s en %s le %s', Units::formatDistance($this->distanceMeters), Units::formatDuration($this->targetSeconds), $this->date->format('d/m/Y'));
    }
}
