<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;

final readonly class UpsertResult
{
    public function __construct(
        public UpsertOutcome $outcome,
        /** Activité gérée par Doctrine : la nouvelle, ou celle déjà connue qui a été conservée. */
        public Activity $activity,
    ) {
    }
}
