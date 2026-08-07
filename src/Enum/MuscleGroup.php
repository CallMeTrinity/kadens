<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Groupe d'entraînement d'une zone travaillée : le découpage qu'on emploie en
 * salle pour dire « aujourd'hui, jambes » ou « aujourd'hui, dos et bras ».
 *
 * **À ne pas confondre avec TargetRegion**, qui range les mêmes 17 zones par
 * étage anatomique (bas du corps / haut du corps / tronc / corps entier). Les
 * deux regroupements coexistent parce qu'ils répondent à deux questions
 * différentes :
 *
 * - `TargetRegion` répond à « où part mon volume ? » — quatre parts qui se
 *   comparent, sur une barre empilée, codées par leur rang dans l'échelle de
 *   gris (`--color-cat-1..4`).
 * - `MuscleGroup` répond à « qu'est-ce que j'ai travaillé ce jour-là ? » — une
 *   étiquette qu'on reconnaît d'un coup d'œil sur une case de calendrier, où
 *   « haut du corps » ne dit rien (un jour de pecs et un jour de dos sont deux
 *   séances qu'on ne confond jamais).
 *
 * **C'est le seul endroit du projet codé par de vraies couleurs** et non par un
 * rang de gris : cinq groupes ne rentrent pas dans quatre nuances. L'exception
 * est bornée à la page `/profile/history` et documentée dans
 * `docs/design-system.md §2`. Le `value` sert directement de suffixe de classe
 * CSS (`.kd-muscle--legs`) — d'où l'absence de méthode `rank()`.
 *
 * L'ordre de déclaration est l'ordre de la légende, pas un ordre de volume :
 * sur une case, les groupes sont triés par nombre de séries décroissant.
 */
enum MuscleGroup: string
{
    case LEGS = 'legs';
    case CHEST = 'chest';
    case BACK = 'back';
    case ARMS = 'arms';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::LEGS => 'Jambes',
            self::CHEST => 'Pectoraux',
            self::BACK => 'Dos',
            self::ARMS => 'Bras',
            self::OTHER => 'Autres',
        };
    }

    /**
     * Le rattachement des 17 zones. Exhaustif par construction : le `match` n'a
     * pas de branche par défaut, donc ajouter un cas à TargetArea fait échouer
     * la compilation ici plutôt que de le ranger silencieusement quelque part.
     *
     * Les épaules, les abdominaux et les obliques tombent dans OTHER : ce
     * découpage suit l'usage (« jambes, pecs, dos, bras »), pas l'anatomie —
     * c'est justement ce qui le distingue de TargetRegion.
     */
    public static function of(TargetArea $area): self
    {
        return match ($area) {
            TargetArea::GLUTES, TargetArea::QUADRICEPS, TargetArea::HAMSTRINGS,
            TargetArea::ADDUCTORS, TargetArea::CALVES, TargetArea::SHINS => self::LEGS,

            TargetArea::CHEST => self::CHEST,

            TargetArea::BACK, TargetArea::LOWER_BACK, TargetArea::TRAPS => self::BACK,

            TargetArea::BICEPS, TargetArea::TRICEPS, TargetArea::FOREARMS => self::ARMS,

            TargetArea::SHOULDERS, TargetArea::ABS, TargetArea::OBLIQUES,
            TargetArea::FULL_BODY => self::OTHER,
        };
    }
}
