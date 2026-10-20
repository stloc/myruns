<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Activités supprimées par l'utilisateur : les imports et la synchro ne les recréent pas.
 */
final class Version20261020055558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activités supprimées (jamais réimportées)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deleted_activity (id VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, start_key VARCHAR(12) NOT NULL, name VARCHAR(255) NOT NULL, deleted_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7FF87A1B86FBB40F ON deleted_activity (start_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE deleted_activity');
    }
}
