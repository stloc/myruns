<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\Units;
use Twig\Attribute\AsTwigFilter;

final class UnitsExtension
{
    #[AsTwigFilter('duration')]
    public function duration(int|float $seconds): string
    {
        return Units::formatDuration($seconds);
    }

    #[AsTwigFilter('gap')]
    public function gap(int|float $seconds): string
    {
        return Units::formatGap($seconds);
    }

    #[AsTwigFilter('pace')]
    public function pace(?float $secondsPerKm): string
    {
        return Units::formatPace($secondsPerKm);
    }

    #[AsTwigFilter('distance')]
    public function distance(float $meters): string
    {
        return Units::formatDistance($meters);
    }
}
