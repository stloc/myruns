<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/**
 * Séance club retirée (jour et heure du profil, semaine sans club) ; une semaine retient à la place
 * les séances changées de jour.
 */
final class Version20261024065941 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Programme : séance club remplacée par l\'échange de jours';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__athlete_profile AS SELECT id, birth_year, max_heart_rate, resting_heart_rate, running_since_year, weight_kg, target_weight_kg, plan_sessions_per_week, last_goal_distance, last_goal_time, last_goal_date FROM athlete_profile');
        $this->addSql('DROP TABLE athlete_profile');
        $this->addSql('CREATE TABLE athlete_profile (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, birth_year INTEGER DEFAULT NULL, max_heart_rate INTEGER DEFAULT NULL, resting_heart_rate INTEGER DEFAULT NULL, running_since_year INTEGER DEFAULT NULL, weight_kg DOUBLE PRECISION DEFAULT NULL, target_weight_kg DOUBLE PRECISION DEFAULT NULL, plan_sessions_per_week INTEGER DEFAULT NULL, last_goal_distance VARCHAR(32) DEFAULT NULL, last_goal_time VARCHAR(32) DEFAULT NULL, last_goal_date DATE DEFAULT NULL)');
        $this->addSql('INSERT INTO athlete_profile (id, birth_year, max_heart_rate, resting_heart_rate, running_since_year, weight_kg, target_weight_kg, plan_sessions_per_week, last_goal_distance, last_goal_time, last_goal_date) SELECT id, birth_year, max_heart_rate, resting_heart_rate, running_since_year, weight_kg, target_weight_kg, plan_sessions_per_week, last_goal_distance, last_goal_time, last_goal_date FROM __temp__athlete_profile');
        $this->addSql('DROP TABLE __temp__athlete_profile');
        $this->addSql('CREATE TEMPORARY TABLE __temp__training_week_override AS SELECT id, sessions, week_start FROM training_week_override');
        $this->addSql('DROP TABLE training_week_override');
        $this->addSql('CREATE TABLE training_week_override (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sessions INTEGER DEFAULT NULL, week_start DATE NOT NULL, days CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO training_week_override (id, sessions, week_start) SELECT id, sessions, week_start FROM __temp__training_week_override');
        $this->addSql('DROP TABLE __temp__training_week_override');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_44E5DA0154D29C0C ON training_week_override (week_start)');
        // Semaines qui ne retenaient qu'un club manqué : plus rien à ajuster.
        $this->addSql('DELETE FROM training_week_override WHERE sessions IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE athlete_profile ADD COLUMN club_weekday INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE athlete_profile ADD COLUMN club_time VARCHAR(5) DEFAULT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__training_week_override AS SELECT id, sessions, week_start FROM training_week_override');
        $this->addSql('DROP TABLE training_week_override');
        $this->addSql('CREATE TABLE training_week_override (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sessions INTEGER DEFAULT NULL, week_start DATE NOT NULL, club BOOLEAN DEFAULT NULL)');
        $this->addSql('INSERT INTO training_week_override (id, sessions, week_start) SELECT id, sessions, week_start FROM __temp__training_week_override');
        $this->addSql('DROP TABLE __temp__training_week_override');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_44E5DA0154D29C0C ON training_week_override (week_start)');
    }
}
