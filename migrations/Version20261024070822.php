<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/**
 * Séances du programme réécrites par l'utilisateur (contenu connu le jour même).
 */
final class Version20261024070822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Programme : séances réécrites par semaine';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE training_week_override ADD COLUMN edits CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__training_week_override AS SELECT id, sessions, days, week_start FROM training_week_override');
        $this->addSql('DROP TABLE training_week_override');
        $this->addSql('CREATE TABLE training_week_override (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sessions INTEGER DEFAULT NULL, days CLOB DEFAULT NULL, week_start DATE NOT NULL)');
        $this->addSql('INSERT INTO training_week_override (id, sessions, days, week_start) SELECT id, sessions, days, week_start FROM __temp__training_week_override');
        $this->addSql('DROP TABLE __temp__training_week_override');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_44E5DA0154D29C0C ON training_week_override (week_start)');
    }
}
