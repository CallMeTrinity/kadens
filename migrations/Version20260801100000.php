<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kadens Live, lot 2 : la table des pierres tombales (ticket KL-14).
 *
 * Une ligne y naît quand un `Exercise` ou une `ScheduledWorkout` est supprimé,
 * et sert à une seule chose : dire au téléphone, dans le delta de
 * `GET /api/bootstrap?since=…`, ce que sa base locale doit oublier. Sans elle,
 * un client hors réseau accumule des fantômes.
 *
 * Le choix de la table plutôt que d'un `deleted_at` sur les entités concernées
 * est argumenté dans `App\Entity\DeletedEntity` : la suppression douce ne
 * supprime pas, elle cache, et il faudrait alors la filtrer dans chaque requête
 * du site — un oubli n'y produit aucune erreur, seulement une ligne morte qui
 * réapparaît.
 *
 * - `entity_key` en VARCHAR(36) : un `id` d'exercice ou un `uuid` de séance
 *   datée. Pas de clé étrangère — elle pointerait sur une ligne qui n'existe
 *   plus, c'est tout l'objet de la table.
 * - `owner_id` nullable en ON DELETE CASCADE : à qui la disparition doit être
 *   annoncée, null pour la bibliothèque globale (elle regarde tout le monde).
 * - Index sur `deleted_at` : c'est la seule borne des lectures (`>= since`) et
 *   de la purge (`< rétention`).
 *
 * `app:deleted:purge` retire les lignes de plus de 180 jours, en cron, comme
 * `app:pairing:purge`.
 */
final class Version20260801100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kadens Live : table deleted_entity (pierres tombales du delta de bootstrap)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deleted_entity (id INT AUTO_INCREMENT NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_key VARCHAR(36) NOT NULL, deleted_at DATETIME NOT NULL, owner_id INT DEFAULT NULL, INDEX IDX_8C4D38967E3C61F9 (owner_id), INDEX idx_deleted_entity_deleted_at (deleted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deleted_entity ADD CONSTRAINT FK_8C4D38967E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deleted_entity DROP FOREIGN KEY FK_8C4D38967E3C61F9');
        $this->addSql('DROP TABLE deleted_entity');
    }
}
