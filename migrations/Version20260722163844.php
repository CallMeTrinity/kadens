<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722163844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ScheduledWorkout: FK owner/workout ON DELETE CASCADE, sourcePlanTemplate ON DELETE SET NULL';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY `FK_58AD3666171175`');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY `FK_58AD367E3C61F9`');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY `FK_58AD36A6CCCFC9`');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD3666171175 FOREIGN KEY (source_plan_template_id) REFERENCES plan_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD367E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD36A6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD367E3C61F9');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD36A6CCCFC9');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD3666171175');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT `FK_58AD367E3C61F9` FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT `FK_58AD36A6CCCFC9` FOREIGN KEY (workout_id) REFERENCES workout (id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT `FK_58AD3666171175` FOREIGN KEY (source_plan_template_id) REFERENCES plan_template (id)');
    }
}
