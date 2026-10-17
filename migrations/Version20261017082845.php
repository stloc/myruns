<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D+ recalculé sur l'altitude du terrain, et courses « trail » reconnues par leur nom.
 */
final class Version20261017082845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D+ du terrain (IGN) ; courses nommées « trail » classées en Trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity ADD COLUMN ground_elevation_gain DOUBLE PRECISION DEFAULT NULL');
        // Même règle que Sport::refinedByName() pour les activités déjà importées (LIKE ignore la casse).
        $this->addSql("UPDATE activity SET sport = 'trail_run' WHERE sport = 'run' AND name LIKE '%trail%'");
    }

    public function down(Schema $schema): void
    {
        // Le reclassement en Trail n'est pas annulé : l'ancien type n'est pas conservé.
        $this->addSql('CREATE TEMPORARY TABLE __temp__activity AS SELECT start_key, sustained_max_heart_rate, id, source, name, sport, start_date, distance_meters, moving_time_seconds, elapsed_time_seconds, elevation_gain, average_heart_rate, max_heart_rate, track_file FROM activity');
        $this->addSql('DROP TABLE activity');
        $this->addSql('CREATE TABLE activity (start_key VARCHAR(12) NOT NULL, sustained_max_heart_rate INTEGER DEFAULT NULL, id VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, name VARCHAR(255) NOT NULL, sport VARCHAR(20) NOT NULL, start_date DATETIME NOT NULL, distance_meters DOUBLE PRECISION NOT NULL, moving_time_seconds INTEGER NOT NULL, elapsed_time_seconds INTEGER NOT NULL, elevation_gain DOUBLE PRECISION DEFAULT NULL, average_heart_rate DOUBLE PRECISION DEFAULT NULL, max_heart_rate DOUBLE PRECISION DEFAULT NULL, track_file VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO activity (start_key, sustained_max_heart_rate, id, source, name, sport, start_date, distance_meters, moving_time_seconds, elapsed_time_seconds, elevation_gain, average_heart_rate, max_heart_rate, track_file) SELECT start_key, sustained_max_heart_rate, id, source, name, sport, start_date, distance_meters, moving_time_seconds, elapsed_time_seconds, elevation_gain, average_heart_rate, max_heart_rate, track_file FROM __temp__activity');
        $this->addSql('DROP TABLE __temp__activity');
        $this->addSql('CREATE INDEX idx_activity_start_key ON activity (start_key)');
        $this->addSql('CREATE INDEX idx_activity_sport_start_date ON activity (sport, start_date)');
    }
}
