<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721083634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE block (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(255) NOT NULL, rounds INT DEFAULT 1 NOT NULL, position INT NOT NULL, label VARCHAR(255) DEFAULT NULL, workout_id INT DEFAULT NULL, INDEX IDX_831B9722A6CCCFC9 (workout_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercise (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, activity VARCHAR(255) NOT NULL, target_areas JSON DEFAULT NULL, media_url VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INT DEFAULT NULL, INDEX IDX_AEDAD51C7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE plan_item (id INT AUTO_INCREMENT NOT NULL, week_number INT NOT NULL, day_of_week INT NOT NULL, notes LONGTEXT DEFAULT NULL, plan_template_id INT DEFAULT NULL, workout_id INT DEFAULT NULL, INDEX IDX_ACDE9ECF163D7D3 (plan_template_id), INDEX IDX_ACDE9ECA6CCCFC9 (workout_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE plan_template (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, duration_weeks INT NOT NULL, slug VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_990528F9989D9B62 (slug), INDEX IDX_990528F97E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE prescribed_exercise (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, prescription_type VARCHAR(255) NOT NULL, sets INT DEFAULT NULL, reps INT DEFAULT NULL, weight_kg DOUBLE PRECISION DEFAULT NULL, duration_seconds INT DEFAULT NULL, distance_meters INT DEFAULT NULL, pace_seconds_per_km INT DEFAULT NULL, target_reps INT DEFAULT NULL, cap_seconds INT DEFAULT NULL, intensity_zone VARCHAR(255) DEFAULT NULL, rest_seconds INT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, block_id INT DEFAULT NULL, exercise_id INT DEFAULT NULL, INDEX IDX_954E4B0AE9ED820C (block_id), INDEX IDX_954E4B0AE934951A (exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE scheduled_workout (id INT AUTO_INCREMENT NOT NULL, scheduled_date DATE NOT NULL, status VARCHAR(255) DEFAULT \'planned\' NOT NULL, completion_notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INT DEFAULT NULL, workout_id INT DEFAULT NULL, source_plan_template_id INT DEFAULT NULL, INDEX IDX_58AD367E3C61F9 (owner_id), INDEX IDX_58AD36A6CCCFC9 (workout_id), INDEX IDX_58AD3666171175 (source_plan_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workout (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, estimated_duration_minutes INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_649FFB72989D9B62 (slug), INDEX IDX_649FFB727E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE block ADD CONSTRAINT FK_831B9722A6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id)');
        $this->addSql('ALTER TABLE exercise ADD CONSTRAINT FK_AEDAD51C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE plan_item ADD CONSTRAINT FK_ACDE9ECF163D7D3 FOREIGN KEY (plan_template_id) REFERENCES plan_template (id)');
        $this->addSql('ALTER TABLE plan_item ADD CONSTRAINT FK_ACDE9ECA6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id)');
        $this->addSql('ALTER TABLE plan_template ADD CONSTRAINT FK_990528F97E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE prescribed_exercise ADD CONSTRAINT FK_954E4B0AE9ED820C FOREIGN KEY (block_id) REFERENCES block (id)');
        $this->addSql('ALTER TABLE prescribed_exercise ADD CONSTRAINT FK_954E4B0AE934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD367E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD36A6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id)');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD3666171175 FOREIGN KEY (source_plan_template_id) REFERENCES plan_template (id)');
        $this->addSql('ALTER TABLE workout ADD CONSTRAINT FK_649FFB727E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE block DROP FOREIGN KEY FK_831B9722A6CCCFC9');
        $this->addSql('ALTER TABLE exercise DROP FOREIGN KEY FK_AEDAD51C7E3C61F9');
        $this->addSql('ALTER TABLE plan_item DROP FOREIGN KEY FK_ACDE9ECF163D7D3');
        $this->addSql('ALTER TABLE plan_item DROP FOREIGN KEY FK_ACDE9ECA6CCCFC9');
        $this->addSql('ALTER TABLE plan_template DROP FOREIGN KEY FK_990528F97E3C61F9');
        $this->addSql('ALTER TABLE prescribed_exercise DROP FOREIGN KEY FK_954E4B0AE9ED820C');
        $this->addSql('ALTER TABLE prescribed_exercise DROP FOREIGN KEY FK_954E4B0AE934951A');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD367E3C61F9');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD36A6CCCFC9');
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD3666171175');
        $this->addSql('ALTER TABLE workout DROP FOREIGN KEY FK_649FFB727E3C61F9');
        $this->addSql('DROP TABLE block');
        $this->addSql('DROP TABLE exercise');
        $this->addSql('DROP TABLE plan_item');
        $this->addSql('DROP TABLE plan_template');
        $this->addSql('DROP TABLE prescribed_exercise');
        $this->addSql('DROP TABLE scheduled_workout');
        $this->addSql('DROP TABLE workout');
    }
}
