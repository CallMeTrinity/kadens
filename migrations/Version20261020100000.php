<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #42 — le récit d'un objectif.
 *
 * - `goal_block` : les blocs ordonnés du récit (texte, photo, activité importée,
 *   séance faite). Supprimés avec l'objectif (CASCADE) ; les références à une
 *   activité ou à une séance sont en SET NULL, pour que retirer une séance du
 *   calendrier n'efface pas ce que l'auteur a écrit autour.
 * - `goal.slug` / `goal.published_at` : la publication opt-in. Les deux NULL
 *   pour tout objectif existant, c'est-à-dire privé : rien à initialiser.
 *
 * Les photos ne sont pas en base : elles vivent sous `var/uploads/goal-photos`
 * (cf. `app.goal_photos_dir`), et `photo_file` n'en garde que le nom.
 */
final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'goal_block (récit d\'objectif) + goal.slug / goal.published_at (publication opt-in)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE goal_block (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, type VARCHAR(20) NOT NULL, body LONGTEXT DEFAULT NULL, photo_file VARCHAR(64) DEFAULT NULL, photo_width INT DEFAULT NULL, photo_height INT DEFAULT NULL, created_at DATETIME NOT NULL, goal_id INT NOT NULL, imported_activity_id INT DEFAULT NULL, scheduled_workout_id INT DEFAULT NULL, INDEX IDX_BBDD65A3667D1AFE (goal_id), INDEX IDX_BBDD65A34EBF10CA (imported_activity_id), INDEX IDX_BBDD65A338BE4770 (scheduled_workout_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE goal_block ADD CONSTRAINT FK_BBDD65A3667D1AFE FOREIGN KEY (goal_id) REFERENCES goal (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE goal_block ADD CONSTRAINT FK_BBDD65A34EBF10CA FOREIGN KEY (imported_activity_id) REFERENCES imported_activity (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE goal_block ADD CONSTRAINT FK_BBDD65A338BE4770 FOREIGN KEY (scheduled_workout_id) REFERENCES scheduled_workout (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE goal ADD slug VARCHAR(255) DEFAULT NULL, ADD published_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FCDCEB2E989D9B62 ON goal (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goal_block DROP FOREIGN KEY FK_BBDD65A3667D1AFE');
        $this->addSql('ALTER TABLE goal_block DROP FOREIGN KEY FK_BBDD65A34EBF10CA');
        $this->addSql('ALTER TABLE goal_block DROP FOREIGN KEY FK_BBDD65A338BE4770');
        $this->addSql('DROP TABLE goal_block');
        $this->addSql('DROP INDEX UNIQ_FCDCEB2E989D9B62 ON goal');
        $this->addSql('ALTER TABLE goal DROP slug, DROP published_at');
    }
}
