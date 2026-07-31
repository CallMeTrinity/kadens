<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kadens Live, lot 2 : le code d'appairage par QR (ticket KL-46).
 *
 * Une seule table, `pairing_code`, dont la forme suit celle d'`api_token` :
 *
 * - `code_hash` en CHAR(64) **unique** : l'empreinte SHA-256 du code, jamais le
 *   code. C'est aussi la colonne par laquelle on le retrouve à la consommation.
 * - `owner_id` en ON DELETE CASCADE : un code n'existe que porté par la session
 *   qui l'a émis, et c'est lui qui décide du compte auquel le téléphone se
 *   rattache.
 * - `used_at` nullable : c'est la colonne sur laquelle porte
 *   `UPDATE ... WHERE used_at IS NULL`. L'usage unique est garanti par la base,
 *   pas par une lecture suivie d'une écriture — deux scans simultanés du même
 *   QR passeraient tous les deux.
 * - `consumed_by_device` en snapshot, et non une relation vers l'`ApiToken`
 *   créé : celui-ci se révoque (KL-12) et emporterait avec lui la trace de
 *   l'appairage, qui sert justement à confirmer sur le desktop *quel* téléphone
 *   vient de se connecter (KL-47).
 *
 * Les lignes vivent deux minutes ; `app:pairing:purge` les retire, en cron.
 */
final class Version20260731140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Kadens Live : table pairing_code (code d'appairage haché, usage unique, TTL 2 min)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pairing_code (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, code_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, consumed_by_device VARCHAR(100) DEFAULT NULL, UNIQUE INDEX uniq_pairing_code_hash (code_hash), INDEX IDX_D0ACCEDE7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pairing_code ADD CONSTRAINT FK_D0ACCEDE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pairing_code DROP FOREIGN KEY FK_D0ACCEDE7E3C61F9');
        $this->addSql('DROP TABLE pairing_code');
    }
}
