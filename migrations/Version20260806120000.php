<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Noms d'exercices bilingues : `exercise.ref_key`, `exercise.name_en` et
 * `user.exercise_language`.
 *
 * - `ref_key` est l'identité **stable** d'une entrée de la bibliothèque globale,
 *   séparée de son libellé. Sans elle, `app:import-exercises` ne sait apparier
 *   que par nom exact : renommer un exercice dans `data/exercises.json` en
 *   créerait un second avec un nouvel `id`, et `logged_exercise.exercise_id`,
 *   `prescribed_exercise.exercise_id` et le cache mobile pointeraient tous sur
 *   l'ancien. Nullable, parce qu'elle ne concerne que la globale — un exercice
 *   perso n'en porte pas, et MariaDB laisse passer plusieurs NULL sous un index
 *   unique, ce qui est précisément ce qu'on lui demande.
 *
 *   La colonne naît **vide** : c'est la commande d'import qui adopte les lignes
 *   existantes en les appariant une dernière fois par nom normalisé, puis pose
 *   la clé. Le faire ici en SQL aurait demandé d'embarquer 301 correspondances
 *   dans une migration.
 *
 * - `name_en` est facultatif : sans lui, l'affichage en anglais retombe sur le
 *   nom français.
 *
 * - `user.exercise_language` est NOT NULL avec un défaut : un affichage n'a pas
 *   de « non renseigné », contrairement au reste de la fiche athlète.
 */
final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Noms d'exercices bilingues : exercise.ref_key + exercise.name_en, user.exercise_language";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise ADD ref_key VARCHAR(128) DEFAULT NULL, ADD name_en VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_exercise_ref_key ON exercise (ref_key)');
        $this->addSql("ALTER TABLE user ADD exercise_language VARCHAR(255) DEFAULT 'fr' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_exercise_ref_key ON exercise');
        $this->addSql('ALTER TABLE exercise DROP ref_key, DROP name_en');
        $this->addSql('ALTER TABLE user DROP exercise_language');
    }
}
