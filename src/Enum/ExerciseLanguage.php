<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La langue dans laquelle un utilisateur veut lire les **noms d'exercices**.
 *
 * Volontairement pas un `locale` : l'app n'a aucune infrastructure de traduction
 * (le dossier `translations/` est vide, toute l'UI est en français en dur) et
 * n'en aura pas pour ça. Ce réglage ne porte que sur `Exercise.name` /
 * `Exercise.nameEn`, parce que les mouvements de salle se nomment couramment en
 * anglais. Les labels, les flashes et les messages de validation restent
 * français quelle que soit la valeur.
 *
 * Le repli est asymétrique et assumé : en `EN`, un exercice sans `nameEn`
 * s'affiche en français plutôt que de laisser un trou. L'inverse n'existe pas,
 * `name` étant obligatoire.
 */
enum ExerciseLanguage: string
{
    case FR = 'fr';
    case EN = 'en';

    public function getLabel(): string
    {
        return match ($this) {
            self::FR => 'Français',
            self::EN => 'Anglais',
        };
    }

    /**
     * Le nom des exercices tel qu'il apparaîtra, dit en une ligne. Sert de hint
     * sous le réglage.
     */
    public function getHint(): string
    {
        return match ($this) {
            self::FR => 'Développé couché, Tirage vertical poitrine…',
            self::EN => 'Bench press, Lat pulldown…',
        };
    }
}
