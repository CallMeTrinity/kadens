<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kadens Live, lot 2 : le jeton d'accès à l'API mobile (ticket KL-10).
 *
 * Une seule table, `api_token`. Deux points structurants :
 *
 * - `token_hash` en CHAR(64) **unique** : c'est l'empreinte SHA-256 du secret,
 *   jamais le secret. L'unicité est autant une garde d'intégrité qu'un index —
 *   l'authentification est une lecture par cette colonne à chaque requête.
 * - `owner_id` en ON DELETE CASCADE : un jeton n'existe que porté par son
 *   compte, contrairement au réalisé qui survit à sa séance source.
 */
final class Version20260730140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kadens Live : table api_token (jeton opaque haché, expiration glissante)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, token_hash CHAR(64) NOT NULL, device_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME NOT NULL, UNIQUE INDEX uniq_api_token_hash (token_hash), INDEX IDX_7BA2F5EB7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB7E3C61F9');
        $this->addSql('DROP TABLE api_token');
    }
}
