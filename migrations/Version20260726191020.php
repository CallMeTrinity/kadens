<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Relation N:N Objectif ↔ Plan : un plan peut préparer plusieurs échéances, une
 * échéance peut se préparer en plusieurs blocs (base puis spécifique).
 *
 * Seule la table de jointure est créée. Le diff généré proposait aussi de
 * renommer des index nommés à la main (prescribed_set, scheduled_workout, user)
 * vers les noms auto de Doctrine : écarté, c'est du bruit sans effet fonctionnel.
 */
final class Version20260726191020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table de jointure plan_template_goal (relation N:N Objectif ↔ Plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan_template_goal (plan_template_id INT NOT NULL, goal_id INT NOT NULL, INDEX IDX_2713DD70F163D7D3 (plan_template_id), INDEX IDX_2713DD70667D1AFE (goal_id), PRIMARY KEY (plan_template_id, goal_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE plan_template_goal ADD CONSTRAINT FK_2713DD70F163D7D3 FOREIGN KEY (plan_template_id) REFERENCES plan_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE plan_template_goal ADD CONSTRAINT FK_2713DD70667D1AFE FOREIGN KEY (goal_id) REFERENCES goal (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_template_goal DROP FOREIGN KEY FK_2713DD70F163D7D3');
        $this->addSql('ALTER TABLE plan_template_goal DROP FOREIGN KEY FK_2713DD70667D1AFE');
        $this->addSql('DROP TABLE plan_template_goal');
    }
}
