<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Superset stocké : `prescribed_exercise.superset_group` lie deux exercices ou
 * plus À L'INTÉRIEUR d'un bloc. Avant, le superset n'était pas stocké du tout —
 * il se déduisait de « le bloc contient exactement 2 exercices », ce qui rendait
 * impossible un bloc de 5 exercices dont 2 seulement sont enchaînés.
 *
 * Reprise des données : chaque bloc à 2 exercices ou plus devient un groupe
 * unique, ce qui préserve à l'identique l'affichage Superset/Circuit de
 * l'ancienne règle. À délier à la main là où ça ne correspondait pas.
 */
final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liaison de superset intra-bloc (prescribed_exercise.superset_group) + reprise des blocs existants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prescribed_exercise ADD superset_group SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE prescribed_exercise pe
            INNER JOIN (
                SELECT block_id FROM prescribed_exercise
                WHERE block_id IS NOT NULL
                GROUP BY block_id HAVING COUNT(*) >= 2
            ) grouped ON grouped.block_id = pe.block_id
            SET pe.superset_group = 1
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prescribed_exercise DROP superset_group');
    }
}
