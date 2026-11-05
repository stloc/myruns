<?php

declare(strict_types=1);

namespace App\Backup;

use App\Settings\LocalSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Sauvegarde complète = la base SQLite (+ les clés d'API si demandé), dans un zip.
 *
 * Export : copie cohérente par `VACUUM INTO`. Import : le contenu est vérifié, l'ancienne base est
 * gardée en `.bak`, puis remplacée ; les migrations manquantes sont appliquées. Un export plus
 * récent que l'application (migration inconnue) est refusé.
 */
final class DatabaseBackup
{
    public const string DATABASE_ENTRY = 'myruns.db';
    public const string MANIFEST_ENTRY = 'manifest.json';
    public const string SETTINGS_ENTRY = 'settings.json';

    private const int FORMAT = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')] private readonly DependencyFactory $migrations,
        private readonly LocalSettings $settings,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Crée le zip dans le dossier temporaire et renvoie son chemin (à supprimer après envoi).
     */
    public function export(bool $withSettings): string
    {
        $snapshot = $this->temporaryPath('snapshot');
        $this->connection->executeStatement('VACUUM INTO '.$this->connection->quote($snapshot));

        $zipPath = $this->temporaryPath('export');
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            $this->filesystem->remove($snapshot);
            throw new BackupException('Impossible de créer l\'archive de sauvegarde.');
        }

        $zip->addFile($snapshot, self::DATABASE_ENTRY);
        $zip->addFromString(self::MANIFEST_ENTRY, json_encode([
            'format' => self::FORMAT,
            'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'migrations' => $this->executedVersions($snapshot),
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        if ($withSettings) {
            $keys = [];
            foreach (LocalSettings::KEYS as $key) {
                if ('' !== $value = $this->settings->get($key)) {
                    $keys[$key] = $value;
                }
            }
            $zip->addFromString(self::SETTINGS_ENTRY, json_encode($keys, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        }

        $zip->close();
        $this->filesystem->remove($snapshot);

        return $zipPath;
    }

    /**
     * Remplace la base courante par celle de la sauvegarde.
     *
     * @return bool vrai si les clés d'API de la sauvegarde ont aussi été reprises
     */
    public function restore(string $archive, bool $withSettings): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive) || false === $zip->locateName(self::DATABASE_ENTRY)) {
            throw new BackupException('Ce fichier n\'est pas une sauvegarde MyRuns (« '.self::DATABASE_ENTRY.' » introuvable).');
        }

        $target = $this->databasePath();
        $incoming = $this->temporaryPath('incoming');
        try {
            $this->extract($zip, self::DATABASE_ENTRY, $incoming);
            $this->assertRestorable($incoming);

            $keys = $withSettings ? $this->readKeys($zip) : [];

            $this->entityManager->clear();
            $this->connection->close();
            if (is_file($target)) {
                $this->filesystem->copy($target, $target.'.bak', true);
            }
            $this->filesystem->copy($incoming, $target, true);
        } finally {
            $zip->close();
            $this->filesystem->remove($incoming);
        }

        $this->migrate();

        if ([] !== $keys) {
            $this->settings->save($keys);
        }

        return [] !== $keys;
    }

    private function databasePath(): string
    {
        $path = $this->connection->getParams()['path'] ?? null;
        if (!\is_string($path) || '' === $path) {
            throw new BackupException('Chemin de la base introuvable.');
        }

        return $path;
    }

    /**
     * Une vraie base SQLite saine, avec le schéma de MyRuns, et pas plus récente que l'application.
     */
    private function assertRestorable(string $database): void
    {
        try {
            $backup = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
            $integrity = $backup->fetchOne('PRAGMA integrity_check');
            $hasActivities = false !== $backup->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'activity'");
            $backup->close();
        } catch (DbalException) {
            throw new BackupException('Le fichier contenu dans l\'archive n\'est pas une base SQLite lisible.');
        }

        if ('ok' !== $integrity) {
            throw new BackupException('La base de la sauvegarde est corrompue (contrôle d\'intégrité SQLite en échec).');
        }
        if (!$hasActivities) {
            throw new BackupException('Cette base n\'est pas une base MyRuns (table des activités absente).');
        }

        $unknown = array_diff($this->executedVersions($database), $this->availableVersions());
        if ([] !== $unknown) {
            throw new BackupException('Cette sauvegarde vient d\'une version plus récente de MyRuns (migration '.reset($unknown).') : mettez l\'application à jour avant de la restaurer.');
        }
    }

    /**
     * @return list<string>
     */
    private function availableVersions(): array
    {
        $versions = [];
        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $versions[] = (string) $migration->getVersion();
        }

        return $versions;
    }

    /**
     * @return list<string>
     */
    private function executedVersions(string $database): array
    {
        $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration();
        $table = $storage instanceof TableMetadataStorageConfiguration ? $storage->getTableName() : 'doctrine_migration_versions';

        try {
            $backup = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
            $versions = $backup->fetchFirstColumn(\sprintf('SELECT version FROM "%s"', $table));
            $backup->close();
        } catch (DbalException) {
            return [];
        }

        return array_values(array_filter($versions, \is_string(...)));
    }

    private function migrate(): void
    {
        $factory = $this->migrations;
        $factory->getMetadataStorage()->ensureInitialized();
        $latest = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);

        if (\count($plan) > 0) {
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(false));
        }
    }

    /**
     * @return array<string, string>
     */
    private function readKeys(\ZipArchive $zip): array
    {
        $json = $zip->getFromName(self::SETTINGS_ENTRY);
        $data = false === $json ? [] : json_decode($json, true);

        $keys = [];
        foreach (\is_array($data) ? $data : [] as $key => $value) {
            if (\in_array($key, LocalSettings::KEYS, true) && \is_string($value) && '' !== trim($value)) {
                $keys[$key] = $value;
            }
        }

        return $keys;
    }

    private function extract(\ZipArchive $zip, string $entry, string $to): void
    {
        $in = $zip->getStream($entry);
        $out = fopen($to, 'wb');
        if (false === $in || false === $out || false === stream_copy_to_stream($in, $out)) {
            throw new BackupException('Impossible de lire la base dans l\'archive.');
        }
        fclose($in);
        fclose($out);
    }

    private function temporaryPath(string $name): string
    {
        $path = $this->filesystem->tempnam(sys_get_temp_dir(), 'myruns-'.$name.'-');
        $this->filesystem->remove($path);

        return $path;
    }
}
