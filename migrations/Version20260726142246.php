<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Relation coach ↔ athlète. Nouvelle table `coaching`, seule table du modèle à
 * relier deux `user` : FK coach/athlete ON DELETE CASCADE (une relation n'a pas
 * de sens sans ses deux parties), requested_by ON DELETE SET NULL (la relation
 * survit à la disparition de l'initiateur). UNIQUE (coach_id, athlete_id) : une
 * seule ligne par couple ordonné, une demande refusée se ré-ouvre en place.
 *
 * Aucune autre table touchée : le contenu créé par le coach reste possédé par
 * l'athlète, aucune colonne « auteur » n'est ajoutée.
 */
final class Version20260726142246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relation coach ↔ athlète : table coaching (demande / acceptation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE coaching (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, responded_at DATETIME DEFAULT NULL, coach_id INT NOT NULL, athlete_id INT NOT NULL, requested_by_id INT DEFAULT NULL, INDEX IDX_CABE08CE3C105691 (coach_id), INDEX IDX_CABE08CEFE6BCB8B (athlete_id), INDEX IDX_CABE08CE4DA1E751 (requested_by_id), UNIQUE INDEX UNIQ_COACHING_PAIR (coach_id, athlete_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE coaching ADD CONSTRAINT FK_CABE08CE3C105691 FOREIGN KEY (coach_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coaching ADD CONSTRAINT FK_CABE08CEFE6BCB8B FOREIGN KEY (athlete_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coaching ADD CONSTRAINT FK_CABE08CE4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coaching DROP FOREIGN KEY FK_CABE08CE3C105691');
        $this->addSql('ALTER TABLE coaching DROP FOREIGN KEY FK_CABE08CEFE6BCB8B');
        $this->addSql('ALTER TABLE coaching DROP FOREIGN KEY FK_CABE08CE4DA1E751');
        $this->addSql('DROP TABLE coaching');
    }
}
