<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Météo pendant les activités (Open-Meteo), en JSON : complétée au prochain import ou par app:weather.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Météo pendant les activités';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity ADD COLUMN weather CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Colonne ni indexée ni liée : SQLite (≥ 3.35) la supprime sans reconstruire la table.
        $this->addSql('ALTER TABLE activity DROP COLUMN weather');
    }
}
