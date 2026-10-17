<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Réponse structurée du coach IA (schéma JSON envoyé au modèle).
 */
final class CoachAnalysis
{
    #[Schema(description: 'Probabilité de réussite de l\'objectif, en pourcentage', minimum: 1, maximum: 99)]
    public int $probabilityPercent = 0;

    #[Schema(description: 'Temps prédit le jour de l\'objectif, au format h:mm:ss ou mm:ss')]
    public string $predictedTime = '';

    #[Schema(description: 'Verdict en une phrase, en français')]
    public string $verdict = '';

    #[Schema(description: 'Justification de l\'écart éventuel avec la probabilité du modèle statistique')]
    public string $probabilityRationale = '';

    /** @var list<string> */
    #[Schema(description: 'Points forts observés dans l\'entraînement', maxItems: 4)]
    public array $strengths = [];

    /** @var list<string> */
    #[Schema(description: 'Risques ou points faibles pour cet objectif', maxItems: 4)]
    public array $risks = [];

    /** @var list<string> */
    #[Schema(description: 'Recommandations concrètes d\'entraînement d\'ici la date de l\'objectif', maxItems: 6)]
    public array $recommendations = [];
}
