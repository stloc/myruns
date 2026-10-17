<?php

declare(strict_types=1);

namespace App\Settings;

use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

/**
 * Fournit à Symfony les réglages locaux comme variables d'environnement.
 *
 * Symfony n'interroge ce chargeur que pour une variable vide ou absente : les valeurs de .env.local
 * et de l'environnement restent prioritaires. Ainsi ai.yaml et le client intervals.icu n'ont pas
 * besoin de savoir d'où vient la clé.
 */
final readonly class SettingsEnvVarLoader implements EnvVarLoaderInterface
{
    public function __construct(
        private LocalSettings $settings,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function loadEnvVars(): array
    {
        return $this->settings->all();
    }
}
