<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Ai\CoachAnalysis;
use App\Util\Units;

/**
 * Mise en forme texte (sans balisage) des résultats, partagée par la TUI et les commandes.
 */
final class PredictionFormatter
{
    /**
     * @return list<string>
     */
    public function fitnessLines(FitnessSnapshot $fitness): array
    {
        if (null === $fitness->vdot) {
            return ['Pas assez de sorties de course récentes (≥ 3 km sur 12 semaines) pour estimer la forme.'];
        }

        $predictions = array_map(
            static fn (array $p): string => \sprintf('%s %s', Units::formatDistance($p['distance']), Units::formatDuration($p['seconds'])),
            $fitness->potentialTimes(),
        );

        return [
            \sprintf('VDOT %.1f (tendance %+.2f/sem.) · %d sortie(s) sur 4 sem. · %.1f km/sem. · sortie longue %.1f km',
                $fitness->vdot, $fitness->vdotTrendPerWeek, $fitness->runsLast4Weeks, $fitness->kmPerWeekLast4Weeks, $fitness->longestRunKmLast8Weeks),
            'Potentiel actuel : '.implode(' · ', $predictions),
            ...(null !== $fitness->heartRateVdot || null !== $fitness->raceVdot ? [\sprintf(
                'Sources : performances %s · cardio %s · course %s',
                null === $fitness->performanceVdot ? '–' : number_format($fitness->performanceVdot, 1, ',', ''),
                null === $fitness->heartRateVdot ? '–' : number_format($fitness->heartRateVdot, 1, ',', ''),
                null === $fitness->raceVdot ? '–' : number_format($fitness->raceVdot, 1, ',', ''),
            )] : []),
        ];
    }

    /**
     * @return list<string>
     */
    public function predictionLines(Prediction $prediction): array
    {
        $lines = [
            'Objectif : '.$prediction->goal->describe(),
            \sprintf('Probabilité de réussite : %d %%  %s', $prediction->probabilityPercent, $this->gauge($prediction->probabilityPercent)),
            \sprintf('Temps prédit : %s aujourd\'hui → %s le jour J (confiance %s)',
                Units::formatDuration($prediction->predictedSecondsToday),
                Units::formatDuration($prediction->predictedSecondsOnGoalDate),
                $prediction->confidenceLabel()),
            \sprintf('VDOT requis %.1f · projeté %.1f · pénalité endurance %.1f',
                $prediction->requiredVdot, $prediction->projectedVdot, $prediction->endurancePenalty),
        ];
        foreach ($prediction->factors as $factor) {
            $lines[] = '  • '.$factor;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function analysisLines(CoachAnalysis $analysis): array
    {
        $lines = [
            \sprintf('Analyse IA : %d %%  %s', $analysis->probabilityPercent, $this->gauge($analysis->probabilityPercent)),
            'Temps prédit par le coach : '.$analysis->predictedTime,
            $analysis->verdict,
            $analysis->probabilityRationale,
        ];
        foreach (['Points forts' => $analysis->strengths, 'Risques' => $analysis->risks, 'Recommandations' => $analysis->recommendations] as $title => $items) {
            if ([] === $items) {
                continue;
            }
            $lines[] = '';
            $lines[] = $title.' :';
            foreach ($items as $item) {
                $lines[] = '  • '.$item;
            }
        }

        return $lines;
    }

    public function gauge(int $percent, int $width = 20): string
    {
        $filled = (int) round($percent / 100 * $width);

        return '['.str_repeat('█', $filled).str_repeat('░', $width - $filled).']';
    }
}
