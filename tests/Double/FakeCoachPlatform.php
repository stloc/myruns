<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Ai\CoachAnalysis;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * Plateforme IA de l'environnement de test : renvoie une analyse fixe, sans appel réseau.
 */
final class FakeCoachPlatform implements PlatformInterface
{
    private readonly InMemoryPlatform $inner;

    public function __construct()
    {
        $this->inner = new InMemoryPlatform(static function (): ObjectResult {
            $analysis = new CoachAnalysis();
            $analysis->probabilityPercent = 58;
            $analysis->predictedTime = '44:40';
            $analysis->verdict = 'Objectif atteignable avec une préparation spécifique.';
            $analysis->recommendations = ['Une séance de seuil par semaine'];

            return new ObjectResult($analysis);
        });
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->inner->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
