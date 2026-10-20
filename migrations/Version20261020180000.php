<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #8 mobile — les réglages de machine (« dossier : 5, jambe : 1 »).
 *
 * `exercise_setup` : une note de texte libre par (utilisateur, exercice), unique
 * en base. Les deux clés étrangères sont en CASCADE : un compte ou un exercice
 * supprimé n'a plus de réglage à garder. Table neuve, rien à initialiser.
 */
final class Version20261020180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'exercise_setup (réglages de machine par utilisateur et par exercice)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exercise_setup (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, exercise_id INT NOT NULL, INDEX IDX_2BA1F27E3C61F9 (owner_id), INDEX IDX_2BA1F2E934951A (exercise_id), UNIQUE INDEX uniq_exercise_setup_owner_exercise (owner_id, exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE exercise_setup ADD CONSTRAINT FK_2BA1F27E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exercise_setup ADD CONSTRAINT FK_2BA1F2E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_setup DROP FOREIGN KEY FK_2BA1F27E3C61F9');
        $this->addSql('ALTER TABLE exercise_setup DROP FOREIGN KEY FK_2BA1F2E934951A');
        $this->addSql('DROP TABLE exercise_setup');
    }
}
