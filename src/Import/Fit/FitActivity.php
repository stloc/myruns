<?php

declare(strict_types=1);

namespace App\Import\Fit;

final class FitActivity
{
    /** Code sport FIT (1 = course, 2 = vélo, 5 = natation, 11 = marche, 17 = randonnée…). */
    public ?int $sport = null;

    /** @var list<FitRecord> */
    public array $records = [];

    /** @var list<FitLap> */
    public array $laps = [];
}
