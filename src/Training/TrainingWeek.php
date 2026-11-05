<?php

declare(strict_types=1);

namespace App\Training;

final readonly class TrainingWeek
{
    /**
     * @param list<TrainingSession> $sessions        dans l'ordre des jours
     * @param bool                  $adjusted        la semaine s'écarte des réglages généraux
     * @param int                   $plannedSessions sorties demandées pour la semaine (la semaine de l'objectif peut en compter moins)
     */
    public function __construct(
        public int $number,
        public \DateTimeImmutable $monday,
        public Phase $phase,
        public bool $recovery,
        public array $sessions,
        public bool $adjusted = false,
        public int $plannedSessions = 0,
    ) {
    }

    public function sunday(): \DateTimeImmutable
    {
        return $this->monday->modify('+6 days');
    }

    public function volumeKm(): float
    {
        return array_sum(array_map(static fn (TrainingSession $session): float => $session->distanceKm, $this->sessions));
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0);

        return $day >= $this->monday && $day <= $this->sunday();
    }

    public function sessionOn(int $weekday): ?TrainingSession
    {
        foreach ($this->sessions as $session) {
            if ($weekday === $session->weekday) {
                return $session;
            }
        }

        return null;
    }

    public function dateOf(TrainingSession $session): ?\DateTimeImmutable
    {
        return null === $session->weekday ? null : $this->monday->modify(\sprintf('+%d days', $session->weekday - 1));
    }
}
