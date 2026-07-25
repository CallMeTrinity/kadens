<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Abonnement calendrier (ICS) : jeton secret nullable + unique sur user.
 */
final class Version20260725120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnement calendrier ICS : colonne calendar_feed_token (nullable, unique) sur user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD calendar_feed_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6493CF23682 ON user (calendar_feed_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6493CF23682 ON user');
        $this->addSql('ALTER TABLE user DROP calendar_feed_token');
    }
}
