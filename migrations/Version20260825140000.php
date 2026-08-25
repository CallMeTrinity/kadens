<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user.max_pullups` / `max_pushups` / `max_dips` : les maximums de répétitions
 * au poids du corps.
 *
 * Trois colonnes et pas une : ces mouvements n'ont pas la même difficulté, et
 * un « max de répétitions » global ne se comparerait à rien. Nullable comme le
 * reste de la fiche — « pas encore mesuré » n'est pas zéro, et zéro voudrait
 * dire quelque chose de faux (aucune traction possible).
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user : maximums de répétitions au poids du corps (tractions, pompes, dips)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD max_pullups INT DEFAULT NULL, ADD max_pushups INT DEFAULT NULL, ADD max_dips INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP max_pullups, DROP max_pushups, DROP max_dips');
    }
}
