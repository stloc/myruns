<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trace GPS simplifiée des activités, pour la carte de la page détail.
 */
final class Version20261017090729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trace GPS simplifiée des activités (carte)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activity_route (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, points CLOB NOT NULL)');
        // SQLite accepte une clé étrangère sur une colonne ajoutée si elle vaut NULL par défaut.
        $this->addSql('ALTER TABLE activity ADD COLUMN route_id INTEGER DEFAULT NULL REFERENCES activity_route (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC74095A34ECB4E6 ON activity (route_id)');
    }

    public function down(Schema $schema): void
    {
        // SQLite ne supprime pas une colonne indexée et liée : on reconstruit la table.
        $this->addSql('DROP INDEX UNIQ_AC74095A34ECB4E6');
        $this->addSql('CREATE TEMPORARY TABLE __temp__activity AS SELECT start_key, sustained_max_heart_rate, ground_elevation_gain, id, source, name, sport, start_date, distance_meters, moving_time_seconds, elapsed_time_seconds, elevation_gain, average_heart_rate, max_heart_rate, track_file FROM activity');
        $this->addSql('DROP TABLE activity');
        $this->addSql('CREATE TABLE activity (start_key VARCHAR(12) NOT NULL, sustained_max_heart_rate INTEGER DEFAULT NULL, ground_elevation_gain DOUBLE PRECISION DEFAULT NULL, id VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, name VARCHAR(255) NOT NULL, sport VARCHAR(20) NOT NULL, start_date DATETIME NOT NULL, distance_meters DOUBLE PRECISION NOT NULL, moving_time_seconds INTEGER NOT NULL, elapsed_time_seconds INTEGER NOT NULL, elevation_gain DOUBLE PRECISION DEFAULT NULL, average_heart_rate DOUBLE PRECISION DEFAULT NULL, max_heart_rate DOUBLE PRECISION DEFAULT NULL, track_file VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO activity (start_key, sustained_max_heart_rate, ground_elevation_gain, id, source, name, sport, start_date, distance_meters, moving_time_seconds, elapsed_time_seconds, elevation_gain, average_heart_rate, max_heart_rate, track_file) SELECT start_key, sustained_max_heart_rate, ground_elevation_gain, id, source, name, sport, start_date, distance_meters, moving_time_seconds, elapsed_time_seconds, elevation_gain, average_heart_rate, max_heart_rate, track_file FROM __temp__activity');
        $this->addSql('DROP TABLE __temp__activity');
        $this->addSql('CREATE INDEX idx_activity_start_key ON activity (start_key)');
        $this->addSql('CREATE INDEX idx_activity_sport_start_date ON activity (sport, start_date)');
        $this->addSql('DROP TABLE activity_route');
    }
}
