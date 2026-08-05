<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kadens Live, lot 1 : le modèle du réalisé (ticket KL-02).
 *
 * Deux nouvelles tables — `logged_exercise` et `logged_set` — et l'extension de
 * `scheduled_workout`, qui devient le point unique où le prévu et le réalisé se
 * rencontrent. Le prescrit (`workout` / `prescribed_exercise` / `prescribed_set`)
 * n'est pas touché : il ne bouge jamais, le réalisé vit à côté.
 *
 * Le changement sensible est le **passage de `workout_id` en ON DELETE SET NULL**.
 * Le CASCADE posé par Version20260722163844 se justifiait par « la séance datée
 * n'a pas de sens sans sa séance source » ; c'est faux dès qu'elle porte le
 * réalisé — supprimer une séance de la bibliothèque effacerait une séance
 * réellement faite. Le snapshot `title` prend le relais pour l'affichage.
 *
 * `uuid` et `title` se peuplent **avant** de poser la contrainte d'unicité et le
 * NOT NULL : l'ordre inverse échouerait sur toutes les lignes déjà en base.
 * `UUID()` de MariaDB est évalué par ligne, chaque séance reçoit donc le sien.
 */
final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kadens Live : tables logged_exercise / logged_set, uuid + title + bornes sur scheduled_workout, FK workout en SET NULL';
    }

    public function up(Schema $schema): void
    {
        // ---- Le réalisé -----------------------------------------------------
        // logged_exercise : CASCADE sur la séance datée (le réalisé n'existe que
        // porté par elle), SET NULL sur l'exercice et sur la ligne du programme
        // (les supprimer ne doit jamais casser un réalisé déjà écrit — le
        // snapshot exercise_name prend le relais).
        $this->addSql('CREATE TABLE logged_exercise (id INT AUTO_INCREMENT NOT NULL, scheduled_workout_id INT NOT NULL, exercise_id INT DEFAULT NULL, source_prescribed_exercise_id INT DEFAULT NULL, exercise_name VARCHAR(255) NOT NULL, position INT NOT NULL, skipped TINYINT(1) DEFAULT 0 NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_BA87971C38BE4770 (scheduled_workout_id), INDEX IDX_BA87971C5C2ECC1C (source_prescribed_exercise_id), INDEX idx_logged_exercise_exercise (exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE logged_exercise ADD CONSTRAINT FK_BA87971C38BE4770 FOREIGN KEY (scheduled_workout_id) REFERENCES scheduled_workout (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE logged_exercise ADD CONSTRAINT FK_BA87971CE934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE logged_exercise ADD CONSTRAINT FK_BA87971C5C2ECC1C FOREIGN KEY (source_prescribed_exercise_id) REFERENCES prescribed_exercise (id) ON DELETE SET NULL');

        // logged_set : `uuid` en CHAR(36), généré par le client mobile. C'est la
        // clé de l'idempotence de PUT /api/schedule/{uuid} — donc de la synchro
        // différée. Unique, sinon une écriture rejouée dupliquerait la série.
        $this->addSql("CREATE TABLE logged_set (id INT AUTO_INCREMENT NOT NULL, logged_exercise_id INT NOT NULL, uuid CHAR(36) NOT NULL, position INT NOT NULL, set_type VARCHAR(255) DEFAULT 'normal' NOT NULL, reps INT DEFAULT NULL, weight_kg DOUBLE PRECISION DEFAULT NULL, duration_seconds INT DEFAULT NULL, rpe INT DEFAULT NULL, completed_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_logged_set_uuid (uuid), INDEX IDX_8104B56A3EA195C0 (logged_exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE logged_set ADD CONSTRAINT FK_8104B56A3EA195C0 FOREIGN KEY (logged_exercise_id) REFERENCES logged_exercise (id) ON DELETE CASCADE');

        // ---- La séance datée ------------------------------------------------
        // uuid nullable d'abord : les lignes existantes n'en ont pas encore.
        $this->addSql('ALTER TABLE scheduled_workout ADD uuid CHAR(36) DEFAULT NULL, ADD title VARCHAR(255) DEFAULT NULL, ADD started_at DATETIME DEFAULT NULL, ADD ended_at DATETIME DEFAULT NULL');

        // Reprise des données, dans cet ordre. UUID() est non déterministe : il
        // est évalué pour chaque ligne, pas une fois pour toutes.
        $this->addSql('UPDATE scheduled_workout SET uuid = UUID() WHERE uuid IS NULL');
        $this->addSql('UPDATE scheduled_workout s INNER JOIN workout w ON w.id = s.workout_id SET s.title = w.title WHERE s.title IS NULL');

        // Seulement maintenant : la colonne est peuplée, la contrainte tient.
        $this->addSql('ALTER TABLE scheduled_workout MODIFY uuid CHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_scheduled_workout_uuid ON scheduled_workout (uuid)');

        // Le changement qui porte tout le ticket (cf. en-tête).
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD36A6CCCFC9');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD36A6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Retour au CASCADE : les séances datées dont la source a déjà été
        // supprimée (workout_id NULL) ne gênent pas, la contrainte ne s'applique
        // qu'aux valeurs non nulles.
        $this->addSql('ALTER TABLE scheduled_workout DROP FOREIGN KEY FK_58AD36A6CCCFC9');
        $this->addSql('ALTER TABLE scheduled_workout ADD CONSTRAINT FK_58AD36A6CCCFC9 FOREIGN KEY (workout_id) REFERENCES workout (id) ON DELETE CASCADE');

        $this->addSql('DROP INDEX uniq_scheduled_workout_uuid ON scheduled_workout');
        $this->addSql('ALTER TABLE scheduled_workout DROP uuid, DROP title, DROP started_at, DROP ended_at');

        $this->addSql('ALTER TABLE logged_set DROP FOREIGN KEY FK_8104B56A3EA195C0');
        $this->addSql('DROP TABLE logged_set');
        $this->addSql('ALTER TABLE logged_exercise DROP FOREIGN KEY FK_BA87971C38BE4770');
        $this->addSql('ALTER TABLE logged_exercise DROP FOREIGN KEY FK_BA87971CE934951A');
        $this->addSql('ALTER TABLE logged_exercise DROP FOREIGN KEY FK_BA87971C5C2ECC1C');
        $this->addSql('DROP TABLE logged_exercise');
    }
}
