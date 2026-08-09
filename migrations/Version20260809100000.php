<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user.body_silhouette` : la silhouette dessinée par la carte musculaire du
 * compositeur de séance.
 *
 * NOT NULL avec un défaut, comme `exercise_language` : un affichage n'a pas de
 * « non renseigné ».
 *
 * **La colonne `sex` ne sert qu'ici, une fois.** Elle donne la valeur initiale
 * pour que le réglage soit déjà juste chez qui a rempli sa fiche athlète, et les
 * deux champs divergent ensuite librement — `sex` est nullable, accepte `other`
 * (qui ne désigne aucun dessin) et sert au score de force normalisé (DOTS).
 * C'est aussi pourquoi le `UPDATE` ne cible **que** `female` : `male` est déjà
 * le défaut de colonne, et `other` comme `NULL` doivent y retomber.
 */
final class Version20260809100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.body_silhouette : silhouette de la carte musculaire (initialisée depuis sex)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user ADD body_silhouette VARCHAR(255) DEFAULT 'male' NOT NULL");
        $this->addSql("UPDATE user SET body_silhouette = 'female' WHERE sex = 'female'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP body_silhouette');
    }
}
