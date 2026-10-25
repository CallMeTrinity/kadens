<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ressenti de séance : forme 1-5 et difficulté 1-10, sur la séance datée (saisie
 * Kadens) et sur l'activité importée (ce que dit la montre).
 *
 * Deux paires de colonnes et pas une : la valeur lue est celle de Kadens si elle
 * existe, sinon celle des activités rattachées, calculée à la lecture. Recopier
 * la note de la montre sur la séance obligerait à savoir, au détachement, si
 * c'est elle qu'il faut effacer. Nullable partout : une séance non notée est
 * l'état normal, et aucune donnée n'est à initialiser (le rafraîchissement
 * Intervals remplira l'historique des courses).
 */
final class Version20261025180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_workout + imported_activity : feeling, session_rpe (ressenti de séance)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_workout ADD feeling SMALLINT DEFAULT NULL, ADD session_rpe SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE imported_activity ADD feeling SMALLINT DEFAULT NULL, ADD session_rpe SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_workout DROP feeling, DROP session_rpe');
        $this->addSql('ALTER TABLE imported_activity DROP feeling, DROP session_rpe');
    }
}
