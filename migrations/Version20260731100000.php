<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kadens Live, lot 2 : la date de dernière hydratation d'un appareil (ticket KL-11).
 *
 * Une colonne, `api_token.last_bootstrap_at`, nullable. Elle ne double pas
 * `last_used_at` : celle-là bouge à chaque requête d'API (l'authenticator la
 * repousse), celle-ci ne bouge qu'au `GET /api/bootstrap` (KL-14). Un téléphone
 * qui pingue tous les jours sans jamais resynchroniser doit se distinguer d'un
 * téléphone à jour — c'est ce que `GET /api/me` renvoie et ce que la liste
 * d'appareils (KL-12) affichera.
 *
 * Nullable et sans valeur par défaut : un appareil qui vient d'être appairé n'a
 * pas encore synchronisé, et « jamais » n'est pas une date.
 */
final class Version20260731100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kadens Live : api_token.last_bootstrap_at (date de dernière hydratation complète)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD last_bootstrap_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP last_bootstrap_at');
    }
}
