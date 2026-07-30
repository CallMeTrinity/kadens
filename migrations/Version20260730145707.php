<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730145707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE prescribed_set DROP FOREIGN KEY `FK_PRESCRIBED_SET_PE`');
        $this->addSql('DROP INDEX idx_prescribed_set_pe ON prescribed_set');
        $this->addSql('CREATE INDEX IDX_BFF53285E93FBA2A ON prescribed_set (prescribed_exercise_id)');
        $this->addSql('ALTER TABLE prescribed_set ADD CONSTRAINT `FK_PRESCRIBED_SET_PE` FOREIGN KEY (prescribed_exercise_id) REFERENCES prescribed_exercise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY `FK_58AD36_SOURCE_PLAN_ITEM`');
        $this->addSql('DROP INDEX idx_58ad36_source_plan_item ON scheduled_workout');
        $this->addSql('CREATE INDEX IDX_58AD367A3AC6EE ON scheduled_workout (source_plan_item_id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT `FK_58AD36_SOURCE_PLAN_ITEM` FOREIGN KEY (source_plan_item_id) REFERENCES plan_item (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX uniq_calendar_feed_token ON user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6493CF23682 ON user (calendar_feed_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE prescribed_set DROP FOREIGN KEY FK_BFF53285E93FBA2A');
        $this->addSql('DROP INDEX idx_bff53285e93fba2a ON prescribed_set');
        $this->addSql('CREATE INDEX IDX_PRESCRIBED_SET_PE ON prescribed_set (prescribed_exercise_id)');
        $this->addSql('ALTER TABLE prescribed_set ADD CONSTRAINT FK_BFF53285E93FBA2A FOREIGN KEY (prescribed_exercise_id) REFERENCES prescribed_exercise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD367A3AC6EE');
        $this->addSql('DROP INDEX idx_58ad367a3ac6ee ON scheduled_workout');
        $this->addSql('CREATE INDEX IDX_58AD36_SOURCE_PLAN_ITEM ON scheduled_workout (source_plan_item_id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD367A3AC6EE FOREIGN KEY (source_plan_item_id) REFERENCES plan_item (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX uniq_8d93d6493cf23682 ON user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CALENDAR_FEED_TOKEN ON user (calendar_feed_token)');
    }
}
