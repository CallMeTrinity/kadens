<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user.activity_colors` : les couleurs d'activité personnalisées.
 *
 * Nullable, et c'est voulu : la colonne ne garde que les **écarts** à la palette
 * par défaut (`ActivityPalette::DEFAULTS`). NULL veut dire « tout par défaut »,
 * ce qui laisse un changement futur de la palette atteindre tous ceux qui n'y
 * ont pas touché. Aucune donnée à initialiser.
 */
final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.activity_colors : couleurs d\'activité personnalisées (écarts au défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD activity_colors JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP activity_colors');
    }
}
