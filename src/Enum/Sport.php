<?php

declare(strict_types=1);

namespace App\Enum;

enum Sport: string
{
    case Run = 'run';
    case TrailRun = 'trail_run';
    case VirtualRun = 'virtual_run';
    case Ride = 'ride';
    case Walk = 'walk';
    case Hike = 'hike';
    case Swim = 'swim';
    case Other = 'other';

    /**
     * Normalise un libellé de type d'activité (export Strava EN/FR, balise GPX, attribut TCX).
     */
    public static function fromLabel(string $label): self
    {
        $label = strtr(mb_strtolower(trim($label)), ['à' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ô' => 'o']);

        return match (true) {
            str_contains($label, 'trail') => self::TrailRun,
            str_contains($label, 'virtual run'), str_contains($label, 'course virtuelle') => self::VirtualRun,
            str_contains($label, 'run'), str_contains($label, 'course'), '9' === $label => self::Run,
            str_contains($label, 'ride'), str_contains($label, 'velo'), str_contains($label, 'biking'), str_contains($label, 'cycling'), '1' === $label => self::Ride,
            str_contains($label, 'hike'), str_contains($label, 'randonnee') => self::Hike,
            str_contains($label, 'walk'), str_contains($label, 'marche') => self::Walk,
            str_contains($label, 'swim'), str_contains($label, 'natation') => self::Swim,
            default => self::Other,
        };
    }

    public function isRunning(): bool
    {
        return \in_array($this, [self::Run, self::TrailRun, self::VirtualRun], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Run => 'Course',
            self::TrailRun => 'Trail',
            self::VirtualRun => 'Virtuelle',
            self::Ride => 'Vélo',
            self::Walk => 'Marche',
            self::Hike => 'Randonnée',
            self::Swim => 'Natation',
            self::Other => 'Autre',
        };
    }
}
