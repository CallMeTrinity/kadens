<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Séries détaillées (mode optionnel des exercices de force). Nouvelle table
 * `prescribed_set` : une ligne = une série individuelle (type + valeurs propres),
 * rattachée à un PrescribedExercise (FK ON DELETE CASCADE : une série n'a pas de
 * sens sans son exercice prescrit). Aucune autre table touchée : le mode scalaire
 * existant (`sets`/`reps`/`weight_kg` sur prescribed_exercise) reste le défaut.
 */
final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Séries détaillées : table prescribed_set (type par série, muscu)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE prescribed_set (id INT AUTO_INCREMENT NOT NULL, prescribed_exercise_id INT NOT NULL, position INT NOT NULL, set_type VARCHAR(255) DEFAULT 'normal' NOT NULL, reps INT DEFAULT NULL, weight_kg DOUBLE PRECISION DEFAULT NULL, duration_seconds INT DEFAULT NULL, INDEX IDX_PRESCRIBED_SET_PE (prescribed_exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE prescribed_set ADD CONSTRAINT FK_PRESCRIBED_SET_PE FOREIGN KEY (prescribed_exercise_id) REFERENCES prescribed_exercise (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prescribed_set DROP FOREIGN KEY FK_PRESCRIBED_SET_PE');
        $this->addSql('DROP TABLE prescribed_set');
    }
}
