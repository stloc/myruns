<?php

declare(strict_types=1);

namespace App\Repository;

enum UpsertOutcome
{
    case Created;
    case Updated;
    case Unchanged;
}
