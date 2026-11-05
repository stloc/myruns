<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Backup\BackupException;
use App\Backup\DatabaseBackup;
use App\Settings\LocalSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Export / restauration de la sauvegarde, sur une base SQLite temporaire (jamais celle des tests :
 * DAMA l'enveloppe dans une transaction, incompatible avec VACUUM et le remplacement du fichier).
 */
final class DatabaseBackupTest extends KernelTestCase
{
    private string $dir;
    private Connection $connection;
    private DependencyFactory $migrations;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/myruns-backup-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);

        $this->migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dir.'/myruns.db']);
        $this->seed($this->connection, 'Footing du matin');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function testExportThenRestoreBringsTheDataBack(): void
    {
        $backup = $this->backup();
        $archive = $backup->export(false);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertNotFalse($zip->locateName(DatabaseBackup::DATABASE_ENTRY));
        self::assertFalse($zip->locateName(DatabaseBackup::SETTINGS_ENTRY), 'Les clés ne sont pas exportées par défaut.');
        $manifest = json_decode((string) $zip->getFromName(DatabaseBackup::MANIFEST_ENTRY), true);
        self::assertIsArray($manifest);
        self::assertNotEmpty($manifest['migrations']);
        $zip->close();

        $this->connection->executeStatement("UPDATE activity SET name = 'Modifiée'");
        self::assertFalse($backup->restore($archive, false));

        self::assertSame('Footing du matin', $this->connection->fetchOne('SELECT name FROM activity'));
        self::assertFileExists($this->dir.'/myruns.db.bak');
        (new Filesystem())->remove($archive);
    }

    public function testApiKeysTravelOnlyWhenAsked(): void
    {
        $backup = $this->backup();
        $settings = new LocalSettings($this->dir);
        $settings->save(['INTERVALS_API_KEY' => 'secret-1']);

        $archive = $backup->export(true);
        $settings->save(['INTERVALS_API_KEY' => '']);

        self::assertFalse($backup->restore($archive, false));
        self::assertSame('', $settings->get('INTERVALS_API_KEY'));

        self::assertTrue($backup->restore($archive, true));
        self::assertSame('secret-1', $settings->get('INTERVALS_API_KEY'));
        (new Filesystem())->remove($archive);
    }

    public function testArchiveWithoutDatabaseIsRefused(): void
    {
        $archive = $this->zip(['readme.txt' => 'rien']);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('introuvable');
        $this->backup()->restore($archive, false);
    }

    public function testNotASqliteDatabaseIsRefusedAndNothingChanges(): void
    {
        $archive = $this->zip([DatabaseBackup::DATABASE_ENTRY => str_repeat('pas une base sqlite ', 50)]);

        try {
            $this->backup()->restore($archive, false);
            self::fail('Une exception était attendue.');
        } catch (BackupException) {
            self::assertSame('Footing du matin', $this->connection->fetchOne('SELECT name FROM activity'));
            self::assertFileDoesNotExist($this->dir.'/myruns.db.bak');
        }
    }

    public function testDatabaseWithoutActivityTableIsRefused(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dir.'/other.db']);
        $other->executeStatement('CREATE TABLE something (id INTEGER)');
        $other->close();
        $archive = $this->zip([DatabaseBackup::DATABASE_ENTRY => (string) file_get_contents($this->dir.'/other.db')]);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('table des activités');
        $this->backup()->restore($archive, false);
    }

    public function testBackupFromANewerVersionIsRefused(): void
    {
        $newer = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dir.'/newer.db']);
        $this->seed($newer, 'Futur');
        $newer->executeStatement("INSERT INTO doctrine_migration_versions (version) VALUES ('DoctrineMigrations\\Version29991231235959')");
        $newer->close();
        $archive = $this->zip([DatabaseBackup::DATABASE_ENTRY => (string) file_get_contents($this->dir.'/newer.db')]);

        try {
            $this->backup()->restore($archive, false);
            self::fail('Une exception était attendue.');
        } catch (BackupException $e) {
            self::assertStringContainsString('plus récente', $e->getMessage());
            self::assertSame('Footing du matin', $this->connection->fetchOne('SELECT name FROM activity'));
        }
    }

    private function backup(): DatabaseBackup
    {
        return new DatabaseBackup(
            $this->connection,
            self::getContainer()->get(EntityManagerInterface::class),
            $this->migrations,
            new LocalSettings($this->dir),
        );
    }

    private function seed(Connection $connection, string $name): void
    {
        $connection->executeStatement('CREATE TABLE activity (id VARCHAR(40) PRIMARY KEY, name VARCHAR(255))');
        $connection->executeStatement('INSERT INTO activity (id, name) VALUES (?, ?)', ['1', $name]);
        $connection->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY, executed_at DATETIME DEFAULT NULL, execution_time INTEGER DEFAULT NULL)');
        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $connection->executeStatement('INSERT INTO doctrine_migration_versions (version) VALUES (?)', [(string) $migration->getVersion()]);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files): string
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
