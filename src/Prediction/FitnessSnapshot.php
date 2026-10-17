<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Entity\Activity;
use App\Entity\RaceResult;

/**
 * État de forme course à pied à une date donnée, calculé sur les 12 dernières semaines.
 */
final readonly class FitnessSnapshot
{
    public const REFERENCE_DISTANCES = [5000.0, 10000.0, 21097.5, 42195.0];

    /**
     * @param ?float         $vdot            VDOT retenu (combinaison des trois sources ci-dessous)
     * @param list<float>    $weeklyKm        volume des 12 dernières semaines, de la plus ancienne à la plus récente
     * @param list<Activity> $topEfforts      sorties ayant servi au calcul du VDOT de performance
     * @param ?float         $performanceVdot moyenne des meilleures sorties d'entraînement
     * @param ?float         $heartRateVdot   médiane des estimations cardiaques (FC max renseignée)
     * @param ?float         $raceVdot        meilleure course officielle des 26 dernières semaines
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public ?float $vdot,
        public float $vdotTrendPerWeek,
        public int $qualifyingRuns,
        public int $runsLast4Weeks,
        public float $kmPerWeekLast4Weeks,
        public float $kmPerWeekLast12Weeks,
        public float $longestRunKmLast8Weeks,
        public array $weeklyKm,
        public array $topEfforts,
        public ?\DateTimeImmutable $lastRunDate,
        public ?float $performanceVdot = null,
        public ?float $heartRateVdot = null,
        public int $heartRateRuns = 0,
        public ?float $raceVdot = null,
        public ?RaceResult $bestRace = null,
        /** false quand une course récente fait foi et que l'estimation cardiaque est écartée */
        public bool $heartRateUsed = false,
    ) {
    }

    public function hasEnoughData(): bool
    {
        return null !== $this->vdot;
    }

    /**
     * Temps réalisables aujourd'hui sur les distances de référence (5 km → marathon).
     *
     * @return list<array{distance: float, seconds: float}>
     */
    public function potentialTimes(): array
    {
        if (null === $this->vdot) {
            return [];
        }

        $vdot = $this->vdot;

        return array_map(
            static fn (float $distance): array => ['distance' => $distance, 'seconds' => Vdot::timeFor($vdot, $distance)],
            self::REFERENCE_DISTANCES,
        );
    }

    public function runsPerWeek(): float
    {
        return $this->runsLast4Weeks / 4;
    }
}
