<?php

declare(strict_types=1);

namespace App\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Réglages saisis dans l'application (clés d'API), stockés dans settings.json du dossier de données.
 *
 * Ils ne servent que si la variable d'environnement correspondante est vide : un .env.local reste
 * prioritaire (voir SettingsEnvVarLoader).
 */
final class LocalSettings
{
    /** Clés modifiables depuis la page Réglages. */
    public const array KEYS = ['INTERVALS_API_KEY', 'ANTHROPIC_API_KEY'];

    private const string SECRET = 'APP_SECRET';

    public function __construct(
        #[Autowire('%app.data_dir%')] private readonly string $dataDir,
    ) {
    }

    public function get(string $key): string
    {
        return $this->read()[$key] ?? '';
    }

    /**
     * Valeur définie hors de l'application (.env.local, variable système) : elle masque le réglage.
     */
    public function environmentValue(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, string> $values clé => valeur ; une valeur vide efface le réglage
     */
    public function save(array $values): void
    {
        $settings = $this->read();
        foreach ($values as $key => $value) {
            if (!\in_array($key, self::KEYS, true)) {
                throw new \InvalidArgumentException(\sprintf('Réglage inconnu : « %s ».', $key));
            }
            $value = trim($value);
            if ('' === $value) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }
        }

        $this->write($settings);
    }

    /**
     * Toutes les valeurs, avec un APP_SECRET propre à l'installation (généré au premier appel).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $settings = $this->read();
        if ('' === ($settings[self::SECRET] ?? '') && $this->hasDataDir()) {
            $settings[self::SECRET] = bin2hex(random_bytes(16));
            $this->write($settings);
        }

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    private function read(): array
    {
        $file = $this->file();
        if (!$this->hasDataDir() || !is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        $settings = [];
        foreach (\is_array($data) ? $data : [] as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * @param array<string, string> $settings
     */
    private function write(array $settings): void
    {
        if (!$this->hasDataDir()) {
            throw new \LogicException(\sprintf('Dossier de données invalide : « %s ».', $this->dataDir));
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($this->dataDir);
        $filesystem->dumpFile($this->file(), json_encode($settings, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        $filesystem->chmod($this->file(), 0o600);
    }

    /**
     * Pendant la compilation du conteneur (lint:container, cache:warmup), Symfony peut appeler le
     * chargeur de variables avant de résoudre %app.data_dir% : on reçoit alors un nom de variable
     * (« env_…_MYSTRAVA_DATA_DIR_… »), relatif, qui créerait un dossier parasite dans le projet.
     * Le vrai dossier de données est toujours un chemin absolu.
     */
    private function hasDataDir(): bool
    {
        return Path::isAbsolute($this->dataDir);
    }

    private function file(): string
    {
        return $this->dataDir.'/settings.json';
    }
}
