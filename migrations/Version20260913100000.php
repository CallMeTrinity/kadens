<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Import du réalisé cardio depuis Intervals.icu.
 *
 * - `intervals_connection` : un compte Intervals par utilisateur (clé d'API
 *   chiffrée, jamais en clair), supprimé avec le compte.
 * - `imported_activity` : les activités importées. Unicité sur (`source`,
 *   `external_id`), ce qui rend la synchronisation rejouable. La séance datée
 *   est en `SET NULL` : retirer une séance du calendrier n'efface pas une sortie
 *   réellement courue.
 */
final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import d\'activités : intervals_connection et imported_activity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE intervals_connection (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, sealed_api_key LONGTEXT NOT NULL, athlete_label VARCHAR(255) DEFAULT NULL, synced_through DATE DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_E903062A7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE intervals_connection ADD CONSTRAINT FK_E903062A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE imported_activity (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, scheduled_workout_id INT DEFAULT NULL, source VARCHAR(255) NOT NULL, external_id VARCHAR(64) NOT NULL, sport_type VARCHAR(64) NOT NULL, activity VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, started_at DATETIME NOT NULL, local_date DATE NOT NULL, distance_meters INT DEFAULT NULL, moving_seconds INT DEFAULT NULL, elapsed_seconds INT DEFAULT NULL, elevation_gain_meters INT DEFAULT NULL, average_heart_rate INT DEFAULT NULL, max_heart_rate INT DEFAULT NULL, average_cadence INT DEFAULT NULL, average_watts INT DEFAULT NULL, splits JSON DEFAULT NULL, hr_zone_seconds JSON DEFAULT NULL, imported_at DATETIME NOT NULL, INDEX IDX_5774B9637E3C61F9 (owner_id), INDEX IDX_5774B96338BE4770 (scheduled_workout_id), UNIQUE INDEX uniq_imported_activity_source_external (source, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE imported_activity ADD CONSTRAINT FK_5774B9637E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE imported_activity ADD CONSTRAINT FK_5774B96338BE4770 FOREIGN KEY (scheduled_workout_id) REFERENCES scheduled_workout (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imported_activity DROP FOREIGN KEY FK_5774B96338BE4770');
        $this->addSql('ALTER TABLE imported_activity DROP FOREIGN KEY FK_5774B9637E3C61F9');
        $this->addSql('DROP TABLE imported_activity');
        $this->addSql('ALTER TABLE intervals_connection DROP FOREIGN KEY FK_E903062A7E3C61F9');
        $this->addSql('DROP TABLE intervals_connection');
    }
}
