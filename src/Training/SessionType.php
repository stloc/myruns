<?php

declare(strict_types=1);

namespace App\Training;

enum SessionType: string
{
    case Easy = 'easy';
    case Long = 'long';
    case Strides = 'strides';
    case Hills = 'hills';
    case Threshold = 'threshold';
    case Interval = 'interval';
    case GoalPace = 'goal_pace';
    case Club = 'club';
    case Race = 'race';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'Footing',
            self::Long => 'Sortie longue',
            self::Strides => 'Footing + accélérations',
            self::Hills => 'Côtes',
            self::Threshold => 'Seuil',
            self::Interval => 'VMA',
            self::GoalPace => 'Allure objectif',
            self::Club => 'Séance club',
            self::Race => 'Jour J',
        };
    }

    public function isQuality(): bool
    {
        return \in_array($this, [self::Threshold, self::Interval, self::GoalPace, self::Club, self::Race], true);
    }
}
