<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Entity\AthleteProfile;
use App\Repository\AthleteProfileRepository;
use App\Util\Units;

/**
 * Probabilité d'atteindre un objectif chrono à une date donnée (à la manière de Kiprun Pacer).
 *
 * 1. VDOT requis par l'objectif ;
 * 2. VDOT projeté à la date de l'objectif (forme actuelle + progression plausible) ;
 * 3. pénalité d'endurance si le volume et la sortie longue sont insuffisants pour la distance ;
 * 4. probabilité = Φ((VDOT effectif − VDOT requis) / incertitude).
 */
final class GoalPredictor
{
    /** Progression « standard » d'un coureur régulier (3 sorties/semaine), en VDOT par semaine. */
    private const TRAINING_GAIN_PER_WEEK = 0.08;
    private const MAX_TOTAL_GAIN = 4.0;
    private const MAX_TOTAL_LOSS = -3.0;

    /** Effet maximal d'un changement de poids sur le VDOT (± 3 %). */
    private const MAX_WEIGHT_EFFECT = 0.03;

    public function __construct(
        private readonly FitnessAnalyzer $analyzer,
        private readonly AthleteProfileRepository $profiles,
        private readonly RunningExperience $experience,
    ) {
    }

    public function predict(Goal $goal, \DateTimeImmutable $now = new \DateTimeImmutable()): ?Prediction
    {
        $fitness = $this->analyzer->analyze($now);
        if (null === $fitness->vdot) {
            return null;
        }

        $factors = [];
        $profile = $this->profiles->current();
        $distanceKm = $goal->distanceMeters / 1000;
        $weeks = max(0, (int) ceil(((int) $now->diff($goal->date)->days) / 7));
        $required = Vdot::fromPerformance($goal->distanceMeters, $goal->targetSeconds);

        // Progression : régularité + tendance récente, avec rendements décroissants.
        $consistency = min(1.0, $fitness->runsPerWeek() / 3);
        $trend = max(-0.2, min(0.3, $fitness->vdotTrendPerWeek));
        $weeklyGain = self::TRAINING_GAIN_PER_WEEK * $consistency + 0.5 * $trend;
        $progressFactor = $this->progressFactor($profile, $this->experience->yearsOn($profile, $goal->date, $now), $goal->date);
        if ($weeklyGain > 0) {
            $weeklyGain *= $progressFactor;
        }
        $totalGain = max(self::MAX_TOTAL_LOSS, min(self::MAX_TOTAL_GAIN * $progressFactor, $weeklyGain * $weeks / (1 + $weeks / 26)));
        $projected = $fitness->vdot + $totalGain;
        $factors[] = \sprintf('Forme actuelle VDOT %.1f, progression estimée %+.1f d\'ici l\'objectif (%d sem.)', $fitness->vdot, $totalGain, $weeks);
        foreach ($this->fitnessSourceFactors($fitness) as $factor) {
            $factors[] = $factor;
        }
        if (abs($progressFactor - 1) > 0.01) {
            $factors[] = \sprintf('Progression ajustée ×%.2f selon l\'âge et l\'ancienneté', $progressFactor);
        }
        if (null === $profile->runningSinceYear && null !== ($since = $this->experience->inferredSinceYear($now))) {
            $factors[] = \sprintf('Ancienneté déduite de l\'historique : course depuis %d au moins (à préciser dans le profil)', $since);
        }

        // Poids : le VDOT s'exprime par kg ; on ne retient que la moitié de l'effet théorique, plafonnée.
        $weightEffect = 0.0;
        if (null !== $profile->weightKg && null !== $profile->targetWeightKg && $profile->targetWeightKg !== $profile->weightKg) {
            $weightEffect = max(-self::MAX_WEIGHT_EFFECT, min(self::MAX_WEIGHT_EFFECT, 0.5 * ($profile->weightKg / $profile->targetWeightKg - 1)));
            $projected *= 1 + $weightEffect;
            $factors[] = \sprintf('Poids visé %.1f kg (actuel %.1f kg) : %+.1f %% sur l\'indice de forme', $profile->targetWeightKg, $profile->weightKg, 100 * $weightEffect);
        }

        if ($consistency < 0.67) {
            $factors[] = \sprintf('Régularité faible : %.1f sortie(s)/semaine sur 4 semaines', $fitness->runsPerWeek());
        }

        // Endurance : volume hebdo et sortie longue nécessaires, projetés avec une montée
        // en charge prudente (+3 %/semaine, plafonnée à +30 %).
        $penalty = 0.0;
        if ($distanceKm > 10) {
            $distanceWeight = min(1.0, ($distanceKm - 10) / 32);
            $growth = min(1.3, 1 + 0.03 * $weeks);
            $idealVolume = 15 + $distanceKm;
            $idealLongest = min(0.75 * $distanceKm, 30);
            $volume = $fitness->kmPerWeekLast4Weeks * $growth;
            $longest = $fitness->longestRunKmLast8Weeks * $growth;
            $volumeShortfall = max(0.0, 1 - $volume / $idealVolume);
            $longestShortfall = max(0.0, 1 - $longest / $idealLongest);
            $penalty = round((2.0 * $volumeShortfall + 2.5 * $longestShortfall) * (0.4 + 0.6 * $distanceWeight), 2);

            if ($volumeShortfall > 0.1) {
                $factors[] = \sprintf('Volume %.0f km/sem. (cible ≈ %.0f km/sem. pour %s)', $fitness->kmPerWeekLast4Weeks, $idealVolume, Units::formatDistance($goal->distanceMeters));
            }
            if ($longestShortfall > 0.1) {
                $factors[] = \sprintf('Sortie longue max %.1f km sur 8 semaines (cible ≈ %.0f km)', $fitness->longestRunKmLast8Weeks, $idealLongest);
            }
        }
        $effective = $projected - $penalty;

        // Incertitude : horizon lointain, progression supposée (non acquise), peu de données, distance longue.
        $sigma = 1.0 + 0.12 * sqrt($weeks) + 0.4 * abs($totalGain) + 0.5 * abs($weightEffect * $fitness->vdot);
        if (null !== $fitness->raceVdot && $fitness->raceVdot >= $fitness->vdot - 2) {
            // Une course officielle récente cohérente avec la forme estimée : donnée fiable.
            $sigma = max(0.8, $sigma - 0.3);
        } elseif ($fitness->qualifyingRuns < 5) {
            $sigma += 1.5;
            $factors[] = \sprintf('Peu de données exploitables (%d sortie(s) ≥ 3 km)', $fitness->qualifyingRuns);
        } elseif ($fitness->qualifyingRuns < 10) {
            $sigma += 0.7;
        }
        if ($distanceKm > 21.1) {
            $sigma += 0.4;
        }

        // Décimales à la française dans les explications (sprintf formate toujours avec un point).
        $factors = array_map(static fn (string $factor): string => preg_replace('/(\d)\.(\d)/', '$1,$2', $factor) ?? $factor, $factors);

        $probability = self::normalCdf(($effective - $required) / $sigma);
        $probabilityPercent = (int) round(100 * max(0.01, min(0.99, $probability)));

        return new Prediction(
            goal: $goal,
            fitness: $fitness,
            probabilityPercent: $probabilityPercent,
            requiredVdot: round($required, 2),
            projectedVdot: round($projected, 2),
            endurancePenalty: $penalty,
            predictedSecondsToday: Vdot::timeFor($fitness->vdot - $penalty, $goal->distanceMeters),
            predictedSecondsOnGoalDate: Vdot::timeFor($effective, $goal->distanceMeters),
            uncertainty: round($sigma, 2),
            weeksToGoal: $weeks,
            factors: $factors,
        );
    }

    /**
     * Rythme de progression selon l'âge (les gains ralentissent avec les années) et l'ancienneté
     * (un débutant progresse plus vite qu'un coureur proche de son plafond).
     */
    private function progressFactor(AthleteProfile $profile, ?int $years, \DateTimeImmutable $goalDate): float
    {
        $age = $profile->ageOn($goalDate);
        $ageFactor = match (true) {
            null === $age, $age < 30 => 1.0,
            $age < 40 => 0.9,
            $age < 50 => 0.8,
            $age < 60 => 0.65,
            default => 0.5,
        };

        $experienceFactor = match (true) {
            null === $years => 1.0,
            $years < 2 => 1.3,
            $years <= 5 => 1.0,
            default => 0.8,
        };

        return $ageFactor * $experienceFactor;
    }

    /**
     * @return list<string>
     */
    private function fitnessSourceFactors(FitnessSnapshot $fitness): array
    {
        $factors = [];
        if (null !== $fitness->heartRateVdot) {
            $factors[] = $fitness->heartRateUsed
                ? \sprintf('Estimation cardiaque VDOT %.1f sur %d sortie(s) (performances seules : %s)',
                    $fitness->heartRateVdot, $fitness->heartRateRuns, null === $fitness->performanceVdot ? '–' : number_format($fitness->performanceVdot, 1))
                : \sprintf('Estimation cardiaque VDOT %.1f non retenue : une course récente est plus fiable', $fitness->heartRateVdot);
        }
        if (null !== $fitness->bestRace && null !== $fitness->raceVdot) {
            $factors[] = \sprintf('Course de référence : %s en %s le %s (VDOT %.1f)',
                Units::formatDistance($fitness->bestRace->distanceMeters), Units::formatDuration($fitness->bestRace->timeSeconds),
                $fitness->bestRace->date->format('d/m/Y'), $fitness->raceVdot);
        }

        return $factors;
    }

    /**
     * Fonction de répartition de la loi normale centrée réduite (approximation d'Abramowitz-Stegun).
     */
    public static function normalCdf(float $x): float
    {
        $t = 1 / (1 + 0.2316419 * abs($x));
        $d = 0.3989423 * exp(-$x * $x / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));

        return $x >= 0 ? 1 - $p : $p;
    }
}
