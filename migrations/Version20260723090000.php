<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PlanItem: FK workout ON DELETE CASCADE.
 *
 * Un PlanItem n'est qu'un placement de séance dans une trame : supprimer la
 * séance doit la retirer de toutes les cases, pas échouer sur la contrainte.
 */
final class Version20260723090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanItem: FK workout ON DELETE CASCADE';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_item DROP FOREIGN KEY FK_ACDE9ECA6CCCFC9');
        $this->addSql('ALTER TABLE plan_item ADD CONSTRAINT FK_ACDE9ECA6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_item DROP FOREIGN KEY FK_ACDE9ECA6CCCFC9');
        $this->addSql('ALTER TABLE plan_item ADD CONSTRAINT FK_ACDE9ECA6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id)');
    }
}
