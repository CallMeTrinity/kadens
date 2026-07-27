<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enrichissement cardio : zones cardio (BPM) sur `user` (FC max/repos + bornes de
 * zone surchargeables) et champs RPE + dénivelé positif sur `prescribed_exercise`.
 * Toutes les colonnes sont nullable (aucune donnée à rétro-remplir).
 */
final class Version20260725202747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zones cardio BPM (user) + RPE et dénivelé (prescribed_exercise)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prescribed_exercise ADD elevation_gain_meters INT DEFAULT NULL, ADD rpe INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD max_heart_rate INT DEFAULT NULL, ADD resting_heart_rate INT DEFAULT NULL, ADD hr_zone1_max INT DEFAULT NULL, ADD hr_zone2_max INT DEFAULT NULL, ADD hr_zone3_max INT DEFAULT NULL, ADD hr_zone4_max INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prescribed_exercise DROP elevation_gain_meters, DROP rpe');
        $this->addSql('ALTER TABLE user DROP max_heart_rate, DROP resting_heart_rate, DROP hr_zone1_max, DROP hr_zone2_max, DROP hr_zone3_max, DROP hr_zone4_max');
    }
}
