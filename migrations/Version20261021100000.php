<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le pseudo, libellé d'affichage d'un compte (l'email reste l'identifiant).
 *
 * - `user.username` : facultatif, donc NULL pour tout compte existant ; l'index
 *   unique tolère plusieurs NULL. Il hérite de la collation de la table
 *   (utf8mb4_general_ci) : l'unicité ignore la casse et les accents.
 * - `user.username_prompt_dismissed_at` : le « non merci » à l'invitation.
 *   NULL partout, si bien que chaque compte existant la verra une fois.
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.username (pseudo unique, facultatif) + user.username_prompt_dismissed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD username VARCHAR(30) DEFAULT NULL, ADD username_prompt_dismissed_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USERNAME ON user (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USERNAME ON user');
        $this->addSql('ALTER TABLE user DROP username, DROP username_prompt_dismissed_at');
    }
}
