<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Progression & plan vivant :
 * - workout.plan_local : marque les copies privées d'une trame (fork à la pose),
 *   exclues de la bibliothèque de séances.
 * - scheduled_workout.source_plan_item_id + plan_anchor_date : rattache une séance
 *   datée à la case du plan dont elle est issue (resync « plan vivant ») et garde
 *   l'ancre d'instanciation pour dater les cases ajoutées après coup.
 */
final class Version20260724120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workout.planLocal + ScheduledWorkout.sourcePlanItem/planAnchorDate (progression & plan vivant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD plan_local TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE scheduled_workout ADD source_plan_item_id INT DEFAULT NULL, ADD plan_anchor_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD36_SOURCE_PLAN_ITEM FOREIGN KEY (source_plan_item_id) REFERENCES plan_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_58AD36_SOURCE_PLAN_ITEM ON scheduled_workout (source_plan_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD36_SOURCE_PLAN_ITEM');
        $this->addSql('DROP INDEX IDX_58AD36_SOURCE_PLAN_ITEM ON scheduled_workout');
        $this->addSql('ALTER TABLE scheduled_workout DROP source_plan_item_id, DROP plan_anchor_date');
        $this->addSql('ALTER TABLE workout DROP plan_local');
    }
}
