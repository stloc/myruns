<?php

declare(strict_types=1);

namespace App\Import\Track;

use App\Entity\Activity;

final readonly class ParsedTrack
{
    /**
     * @param list<TrackPoint> $points
     * @param list<TrackLap>   $laps
     */
    public function __construct(
        public Activity $activity,
        public array $points,
        public array $laps = [],
    ) {
    }
}
