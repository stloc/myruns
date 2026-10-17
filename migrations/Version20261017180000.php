<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position de départ des traces, pour la carte des départs de la page Activités.
 */
final class Version20261017180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Position de départ des traces (carte des activités)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_route ADD COLUMN start_latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_route ADD COLUMN start_longitude DOUBLE PRECISION DEFAULT NULL');
    }

    /**
     * Traces déjà importées : 1er point de la trace, lu en PHP (aucune fonction JSON propre à un SGBD).
     */
    public function postUp(Schema $schema): void
    {
        foreach ($this->connection->iterateAssociative('SELECT id, points FROM activity_route') as $row) {
            $points = json_decode(\is_string($row['points']) ? $row['points'] : '', true);
            $first = \is_array($points) && \is_array($points[0] ?? null) ? $points[0] : null;
            if (null === $first || !is_numeric($first[0] ?? null) || !is_numeric($first[1] ?? null)) {
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE activity_route SET start_latitude = ?, start_longitude = ? WHERE id = ?',
                [(float) $first[0], (float) $first[1], $row['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Colonnes ni indexées ni liées : SQLite (≥ 3.35) les supprime sans reconstruire la table.
        $this->addSql('ALTER TABLE activity_route DROP COLUMN start_latitude');
        $this->addSql('ALTER TABLE activity_route DROP COLUMN start_longitude');
    }
}
