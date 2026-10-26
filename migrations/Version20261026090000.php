<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages programmés (back-office super admin) : le message, ses destinataires
 * et la mémoire du « vu », commune au web et au téléphone.
 *
 * Les deux tables de jointure sont en CASCADE des deux côtés : supprimer un
 * compte ou un message n'a rien à préserver de l'autre.
 */
final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'custom_message + custom_message_recipient + custom_message_seen (messages programmés)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE custom_message (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, body LONGTEXT NOT NULL, media_file VARCHAR(64) DEFAULT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, audience VARCHAR(10) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE custom_message_recipient (custom_message_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_F0F299F26C852F83 (custom_message_id), INDEX IDX_F0F299F2A76ED395 (user_id), PRIMARY KEY (custom_message_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE custom_message_seen (custom_message_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_764086DA6C852F83 (custom_message_id), INDEX IDX_764086DAA76ED395 (user_id), PRIMARY KEY (custom_message_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE custom_message_recipient ADD CONSTRAINT FK_F0F299F26C852F83 FOREIGN KEY (custom_message_id) REFERENCES custom_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_message_recipient ADD CONSTRAINT FK_F0F299F2A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_message_seen ADD CONSTRAINT FK_764086DA6C852F83 FOREIGN KEY (custom_message_id) REFERENCES custom_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_message_seen ADD CONSTRAINT FK_764086DAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_message_recipient DROP FOREIGN KEY FK_F0F299F26C852F83');
        $this->addSql('ALTER TABLE custom_message_recipient DROP FOREIGN KEY FK_F0F299F2A76ED395');
        $this->addSql('ALTER TABLE custom_message_seen DROP FOREIGN KEY FK_764086DA6C852F83');
        $this->addSql('ALTER TABLE custom_message_seen DROP FOREIGN KEY FK_764086DAA76ED395');
        $this->addSql('DROP TABLE custom_message');
        $this->addSql('DROP TABLE custom_message_recipient');
        $this->addSql('DROP TABLE custom_message_seen');
    }
}
