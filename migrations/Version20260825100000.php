<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user.deadhang_seconds` : la suspension à la barre la plus longue.
 *
 * Nullable comme les autres records de la fiche athlète : la fiche se remplit
 * progressivement, et « pas encore mesuré » n'est pas zéro. En secondes, comme
 * tous les temps du projet (la saisie mm:ss est l'affaire de `DurationType`).
 */
final class Version20260825100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.deadhang_seconds : record de suspension à la barre (secondes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD deadhang_seconds INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP deadhang_seconds');
    }
}
