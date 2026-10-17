<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma complet pour SQLite (remplace les migrations PostgreSQL d'origine).
 */
final class Version20261017070426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial SQLite : activités, temps de passage, profil, courses officielles, ajustements du programme';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activity (start_key VARCHAR(12) NOT NULL, sustained_max_heart_rate INTEGER DEFAULT NULL, id VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, name VARCHAR(255) NOT NULL, sport VARCHAR(20) NOT NULL, start_date DATETIME NOT NULL, distance_meters DOUBLE PRECISION NOT NULL, moving_time_seconds INTEGER NOT NULL, elapsed_time_seconds INTEGER NOT NULL, elevation_gain DOUBLE PRECISION DEFAULT NULL, average_heart_rate DOUBLE PRECISION DEFAULT NULL, max_heart_rate DOUBLE PRECISION DEFAULT NULL, track_file VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_activity_start_key ON activity (start_key)');
        $this->addSql('CREATE INDEX idx_activity_sport_start_date ON activity (sport, start_date)');
        $this->addSql('CREATE TABLE activity_split (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, kind VARCHAR(3) NOT NULL, position INTEGER NOT NULL, distance_meters DOUBLE PRECISION NOT NULL, seconds DOUBLE PRECISION NOT NULL, average_heart_rate INTEGER DEFAULT NULL, max_heart_rate INTEGER DEFAULT NULL, elevation_gain DOUBLE PRECISION DEFAULT NULL, activity_id VARCHAR(255) NOT NULL, CONSTRAINT FK_AFF7ACA281C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_AFF7ACA281C06096 ON activity_split (activity_id)');
        $this->addSql('CREATE TABLE athlete_profile (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, birth_year INTEGER DEFAULT NULL, max_heart_rate INTEGER DEFAULT NULL, resting_heart_rate INTEGER DEFAULT NULL, running_since_year INTEGER DEFAULT NULL, weight_kg DOUBLE PRECISION DEFAULT NULL, target_weight_kg DOUBLE PRECISION DEFAULT NULL, plan_sessions_per_week INTEGER DEFAULT NULL, club_weekday INTEGER DEFAULT NULL, club_time VARCHAR(5) DEFAULT NULL, last_goal_distance VARCHAR(32) DEFAULT NULL, last_goal_time VARCHAR(32) DEFAULT NULL, last_goal_date DATE DEFAULT NULL)');
        $this->addSql('CREATE TABLE race_result (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, date DATE NOT NULL, distance_meters DOUBLE PRECISION NOT NULL, time_seconds INTEGER NOT NULL, name VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE TABLE training_week_override (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, club BOOLEAN DEFAULT NULL, sessions INTEGER DEFAULT NULL, week_start DATE NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_44E5DA0154D29C0C ON training_week_override (week_start)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_split');
        $this->addSql('DROP TABLE activity');
        $this->addSql('DROP TABLE athlete_profile');
        $this->addSql('DROP TABLE race_result');
        $this->addSql('DROP TABLE training_week_override');
    }
}
