<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Entity\AthleteProfile;
use App\Repository\ActivityRepository;

/**
 * Ancienneté de pratique : l'année saisie dans le profil, sinon l'année de la première course de
 * l'historique. Celle-ci n'est qu'un minimum (on courait peut-être avant l'archive) et n'est retenue
 * que si l'historique couvre au moins 2 ans : un historique court ne dit pas si l'on débute.
 */
final class RunningExperience
{
    private const MIN_HISTORY_YEARS = 2;

    public function __construct(private readonly ActivityRepository $activities)
    {
    }

    /**
     * Année de début déduite de l'historique, ou null s'il est trop court pour conclure.
     */
    public function inferredSinceYear(\DateTimeImmutable $now = new \DateTimeImmutable()): ?int
    {
        $first = $this->activities->firstRunDate();
        if (null === $first || $first > $now->modify(\sprintf('-%d years', self::MIN_HISTORY_YEARS))) {
            return null;
        }

        return (int) $first->format('Y');
    }

    public function sinceYear(AthleteProfile $profile, \DateTimeImmutable $now = new \DateTimeImmutable()): ?int
    {
        return $profile->runningSinceYear ?? $this->inferredSinceYear($now);
    }

    public function yearsOn(AthleteProfile $profile, \DateTimeImmutable $date, \DateTimeImmutable $now = new \DateTimeImmutable()): ?int
    {
        $since = $this->sinceYear($profile, $now);

        return null === $since ? null : max(0, (int) $date->format('Y') - $since);
    }
}
