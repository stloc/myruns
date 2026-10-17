<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Activity;
use App\Entity\RaceResult;
use App\Prediction\Prediction;
use App\Prediction\RunningExperience;
use App\Repository\ActivityRepository;
use App\Repository\AthleteProfileRepository;
use App\Repository\RaceResultRepository;
use App\Util\Units;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Affine la prédiction statistique avec un LLM, qui lit l'historique d'entraînement comme un coach.
 */
final class AiCoach
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Tu es un entraîneur de course à pied expérimenté (méthode Daniels, planification par blocs).
        On te fournit l'historique d'entraînement d'un coureur, une estimation statistique de sa forme
        (VDOT) et un objectif chronométré à une date donnée.

        Ta mission : estimer la probabilité de réussir l'objectif, comme le ferait Kiprun Pacer.
        - Pars de la probabilité du modèle statistique ; ne t'en écarte de plus de 15 points que si
          l'historique le justifie clairement (sorties au seuil récentes, blessure probable, reprise…),
          et explique toujours l'écart.
        - Les sorties d'entraînement sont souvent sous-maximales : le VDOT de performance peut
          sous-évaluer un coureur qui ne fait que de l'endurance fondamentale. Le VDOT cardiaque
          (si la FC max est connue) et les courses officielles corrigent ce biais.
        - Tiens compte du profil (âge, ancienneté, poids visé) quand il est renseigné.
        - Tiens compte du temps restant, du volume, de la sortie longue et de la régularité.
        - Recommandations concrètes et datées (séances types, volume cible, affûtage), en français.
        PROMPT;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ActivityRepository $activities,
        private readonly AthleteProfileRepository $profiles,
        private readonly RaceResultRepository $races,
        private readonly RunningExperience $experience,
        #[Autowire('%env(AI_MODEL)%')] private readonly string $model,
    ) {
    }

    public function analyze(Prediction $prediction): CoachAnalysis
    {
        if ('' === $this->model) {
            throw new \LogicException('La variable d\'environnement AI_MODEL est vide.');
        }

        $messages = new MessageBag(
            Message::forSystem(self::SYSTEM_PROMPT),
            Message::ofUser(json_encode($this->context($prediction), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
        );

        $result = $this->platform->invoke($this->model, $messages, [
            'response_format' => CoachAnalysis::class,
            'max_tokens' => 4096,
        ])->asObject();

        if (!$result instanceof CoachAnalysis) {
            throw new \UnexpectedValueException('Réponse inattendue du modèle.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Prediction $prediction): array
    {
        $fitness = $prediction->fitness;
        $since = $fitness->date->modify('-12 weeks');
        $profile = $this->profiles->current();

        return [
            'date_du_jour' => $fitness->date->format('Y-m-d'),
            'objectif' => [
                'distance' => Units::formatDistance($prediction->goal->distanceMeters),
                'temps_vise' => Units::formatDuration($prediction->goal->targetSeconds),
                'allure_visee' => Units::formatPace($prediction->goal->targetSeconds / ($prediction->goal->distanceMeters / 1000)),
                'date' => $prediction->goal->date->format('Y-m-d'),
                'semaines_restantes' => $prediction->weeksToGoal,
            ],
            'modele_statistique' => [
                'probabilite_pourcent' => $prediction->probabilityPercent,
                'vdot_actuel' => $fitness->vdot,
                'vdot_requis' => $prediction->requiredVdot,
                'vdot_projete_date_objectif' => $prediction->projectedVdot,
                'penalite_endurance' => $prediction->endurancePenalty,
                'temps_predit_aujourdhui' => Units::formatDuration($prediction->predictedSecondsToday),
                'temps_predit_date_objectif' => Units::formatDuration($prediction->predictedSecondsOnGoalDate),
                'incertitude_vdot' => $prediction->uncertainty,
                'facteurs' => $prediction->factors,
            ],
            'profil' => [
                'age_le_jour_j' => $profile->ageOn($prediction->goal->date),
                'annees_de_pratique' => $this->experience->yearsOn($profile, $fitness->date, $fitness->date),
                'annees_de_pratique_source' => null !== $profile->runningSinceYear ? 'saisie' : 'première course de l\'historique : minimum, la pratique a pu commencer avant',
                'fc_max' => $profile->maxHeartRate,
                'fc_repos' => $profile->restingHeartRate,
                'poids_kg' => $profile->weightKg,
                'poids_vise_kg' => $profile->targetWeightKg,
            ],
            'courses_officielles' => array_map(
                static fn (RaceResult $race): array => [
                    'date' => $race->date->format('Y-m-d'),
                    'nom' => $race->name,
                    'distance' => Units::formatDistance($race->distanceMeters),
                    'temps' => Units::formatDuration($race->timeSeconds),
                    'vdot' => round($race->vdot(), 1),
                ],
                \array_slice($this->races->findLatestFirst(), 0, 10),
            ),
            'forme' => [
                'vdot_performances' => $fitness->performanceVdot,
                'vdot_cardiaque' => $fitness->heartRateVdot,
                'sorties_estimation_cardiaque' => $fitness->heartRateRuns,
                'vdot_meilleure_course_26_semaines' => $fitness->raceVdot,
                'km_par_semaine_4_dernieres' => $fitness->kmPerWeekLast4Weeks,
                'km_par_semaine_12_dernieres' => $fitness->kmPerWeekLast12Weeks,
                'sorties_4_dernieres_semaines' => $fitness->runsLast4Weeks,
                'sortie_longue_max_8_semaines_km' => $fitness->longestRunKmLast8Weeks,
                'tendance_vdot_par_semaine' => $fitness->vdotTrendPerWeek,
                'km_par_semaine_historique' => $fitness->weeklyKm,
            ],
            'sorties_12_semaines' => array_map(
                static fn (Activity $a): array => [
                    'date' => $a->startDate->format('Y-m-d'),
                    'type' => $a->sport->label(),
                    'nom' => $a->name,
                    'km' => round($a->distanceMeters / 1000, 2),
                    'duree' => Units::formatDuration($a->movingTimeSeconds),
                    'allure' => Units::formatPace($a->paceSecondsPerKm()),
                    'd_plus_m' => $a->elevationGain,
                    'fc_moy' => $a->averageHeartRate,
                ],
                \array_slice($this->activities->findRunsSince($since), 0, 80),
            ),
        ];
    }
}
