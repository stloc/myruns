<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Variation d'altitude de chaque temps de passage : calculée au prochain import des traces.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Variation d\'altitude des temps de passage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_split ADD COLUMN elevation_change DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Colonne ni indexée ni liée : SQLite (≥ 3.35) la supprime sans reconstruire la table.
        $this->addSql('ALTER TABLE activity_split DROP COLUMN elevation_change');
    }
}
