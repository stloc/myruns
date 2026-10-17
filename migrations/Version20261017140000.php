<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commune du point de départ des activités (géocodage inverse IGN).
 */
final class Version20261017140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commune du point de départ des activités';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity ADD COLUMN city VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Colonne ni indexée ni liée : SQLite (≥ 3.35) la supprime sans reconstruire la table.
        $this->addSql('ALTER TABLE activity DROP COLUMN city');
    }
}
