<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lignée des séances : `workout.origin_id` relie une copie locale de plan à la
 * séance de bibliothèque dont elle descend.
 *
 * Rattrapage de l'existant : jusqu'ici le fork à la pose ne gardait aucune
 * trace de sa source, seul le titre en témoigne. On rattache donc chaque copie
 * `plan_local` à la séance de bibliothèque du même propriétaire qui porte
 * exactement le même titre, **à condition qu'il n'y en ait qu'une**. Un titre
 * partagé par deux séances de bibliothèque est ambigu : on préfère ne rien
 * rattacher que de rattacher faux. Une copie renommée depuis reste orpheline.
 *
 * La table dérivée contourne la limite MariaDB qui interdit de lire, en
 * sous-requête, la table qu'on met à jour.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'workout.origin_id (lignée des copies locales de plan) + rattachement par titre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD origin_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD CONSTRAINT FK_649FFB7256A273CC FOREIGN KEY (origin_id) REFERENCES workout (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_649FFB7256A273CC ON workout (origin_id)');

        $this->addSql(<<<'SQL'
            UPDATE workout w
            JOIN (
                SELECT owner_id, title, MIN(id) AS id
                FROM workout
                WHERE plan_local = 0 AND owner_id IS NOT NULL
                GROUP BY owner_id, title
                HAVING COUNT(*) = 1
            ) lib ON lib.owner_id = w.owner_id AND lib.title = w.title
            SET w.origin_id = lib.id
            WHERE w.plan_local = 1 AND w.origin_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout DROP FOREIGN KEY FK_649FFB7256A273CC');
        $this->addSql('DROP INDEX IDX_649FFB7256A273CC ON workout');
        $this->addSql('ALTER TABLE workout DROP origin_id');
    }
}
