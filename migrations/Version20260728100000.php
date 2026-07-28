<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bloc-notes privé du propriétaire sur une séance et sur un plan.
 *
 * Distinct de `description`, qui reste le texte « public » de l'entité (lu par le
 * coach, par le partage public, par l'export). `notes` n'est visible que de
 * `owner` : c'est le fourre-tout où l'on jette le déroulé en vrac avant de le
 * transformer en blocs ou en cases.
 */
final class Version20260728100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bloc-notes privé du propriétaire : workout.notes et plan_template.notes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE plan_template ADD notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout DROP notes');
        $this->addSql('ALTER TABLE plan_template DROP notes');
    }
}
