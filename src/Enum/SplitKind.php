<?php

declare(strict_types=1);

namespace App\Enum;

enum SplitKind: string
{
    /** Passage calculé à chaque kilomètre. */
    case Kilometer = 'km';
    /** Tour enregistré par la montre (touche Lap, tour automatique, séance programmée). */
    case Lap = 'lap';
}
