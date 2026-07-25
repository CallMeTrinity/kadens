<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725073629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche athlète : colonnes profil nullable sur user (identité, records de force, records d\'endurance) + updated_at.';
    }

    public function up(Schema $schema): void
    {
        // Fiche athlète : tout est nullable (aucune donnée existante à migrer).
        $this->addSql('ALTER TABLE user ADD updated_at DATETIME DEFAULT NULL, ADD birth_date DATE DEFAULT NULL, ADD sex VARCHAR(255) DEFAULT NULL, ADD height_cm INT DEFAULT NULL, ADD weight_kg DOUBLE PRECISION DEFAULT NULL, ADD training_years INT DEFAULT NULL, ADD main_goal VARCHAR(255) DEFAULT NULL, ADD bio LONGTEXT DEFAULT NULL, ADD squat1rm_kg DOUBLE PRECISION DEFAULT NULL, ADD bench1rm_kg DOUBLE PRECISION DEFAULT NULL, ADD deadlift1rm_kg DOUBLE PRECISION DEFAULT NULL, ADD ohp1rm_kg DOUBLE PRECISION DEFAULT NULL, ADD weighted_pullup_kg DOUBLE PRECISION DEFAULT NULL, ADD run5k_seconds INT DEFAULT NULL, ADD run10k_seconds INT DEFAULT NULL, ADD half_marathon_seconds INT DEFAULT NULL, ADD marathon_seconds INT DEFAULT NULL, ADD cycling_ftp_watts INT DEFAULT NULL, ADD swim100m_seconds INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP updated_at, DROP birth_date, DROP sex, DROP height_cm, DROP weight_kg, DROP training_years, DROP main_goal, DROP bio, DROP squat1rm_kg, DROP bench1rm_kg, DROP deadlift1rm_kg, DROP ohp1rm_kg, DROP weighted_pullup_kg, DROP run5k_seconds, DROP run10k_seconds, DROP half_marathon_seconds, DROP marathon_seconds, DROP cycling_ftp_watts, DROP swim100m_seconds');
    }
}
