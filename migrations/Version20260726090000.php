<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Objectifs datés (feature « Objectif / événement cible »). Nouvelle table `goal`
 * owner-only (FK owner ON DELETE CASCADE : un objectif n'a pas de sens sans son
 * propriétaire). Aucune autre table touchée.
 */
final class Version20260726090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Objectifs datés : table goal (owner-only, échéance journée entière)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE goal (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, activity VARCHAR(255) DEFAULT NULL, priority VARCHAR(20) DEFAULT 'a' NOT NULL, target_date DATE NOT NULL, target_value VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, outcome VARCHAR(20) DEFAULT NULL, result_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INT DEFAULT NULL, INDEX IDX_FCDCEB2E7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE goal ADD CONSTRAINT FK_FCDCEB2E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goal DROP FOREIGN KEY FK_FCDCEB2E7E3C61F9');
        $this->addSql('DROP TABLE goal');
    }
}
