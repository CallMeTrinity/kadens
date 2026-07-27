<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table `logged_set` : la moitié « réalisé » de la boucle prévu vs réalisé.
 *
 * Rien n'est ajouté à la prescription : `prescribed_set` continue de dire ce
 * qu'il faut faire, cette table dit ce qui a été fait, à une date donnée. C'est
 * ce qui permet de valider les séries d'une séance sans écrire dans la séance de
 * bibliothèque ni dans les autres dates qui la référencent.
 *
 * Le pointage se fait sur (prescribed_exercise_id, set_index) et non sur un
 * prescribed_set_id : en mode scalaire, aucune ligne de série n'existe en base
 * alors que la vue en déroule N (cf. PlanFlattener::setLines). L'index couvre
 * les deux modes de saisie sans forcer la matérialisation du détail.
 *
 * Aucune reprise de données : il n'existait aucun réalisé détaillé auparavant,
 * seul le statut global de ScheduledWorkout, qui n'est pas touché.
 */
final class Version20260727180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Séries réalisées (logged_set), séparées de la prescription.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE logged_set (
                id INT AUTO_INCREMENT NOT NULL,
                scheduled_workout_id INT NOT NULL,
                prescribed_exercise_id INT NOT NULL,
                set_index INT NOT NULL,
                reps INT DEFAULT NULL,
                weight_kg DOUBLE PRECISION DEFAULT NULL,
                duration_seconds INT DEFAULT NULL,
                completed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_logged_set_scheduled (scheduled_workout_id),
                INDEX IDX_logged_set_prescribed (prescribed_exercise_id),
                UNIQUE INDEX uniq_logged_set_line (scheduled_workout_id, prescribed_exercise_id, set_index),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // Retirer une séance du planning ou un exercice d'une séance efface le
        // réalisé correspondant : un log sans sa ligne prescrite n'est plus
        // interprétable (on ne saurait plus à quoi le comparer).
        $this->addSql('ALTER TABLE logged_set ADD CONSTRAINT FK_logged_set_scheduled FOREIGN KEY (scheduled_workout_id) REFERENCES scheduled_workout (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE logged_set ADD CONSTRAINT FK_logged_set_prescribed FOREIGN KEY (prescribed_exercise_id) REFERENCES prescribed_exercise (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE logged_set');
    }
}
