<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ExerciseLanguage;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le **seul** endroit qui décide sous quel libellé un exercice s'affiche.
 *
 * Avant, chaque template lisait `.name` en direct — treize points d'affichage,
 * treize occasions d'oublier une règle. Une langue au choix en ajoute trois : le
 * repli quand le nom anglais manque, le repli quand l'exercice a été supprimé,
 * et le cas anonyme. Elles vivent ici, et nulle part ailleurs.
 *
 * ## Les trois règles
 *
 * 1. **La langue vient de l'utilisateur courant**, `FR` s'il n'y en a pas :
 *    page publique de partage, flux ICS, export — un lecteur sans compte lit la
 *    langue d'origine de la bibliothèque.
 * 2. **En anglais, un exercice sans `nameEn` s'affiche en français.** Jamais de
 *    trou : `nameEn` est facultatif par construction (les mouvements dont le nom
 *    français EST l'anglais n'en portent pas — « Dips », « Fartlek »).
 * 3. **Un exercice supprimé retombe sur le libellé qu'on lui passe.**
 *    `LoggedExercise.exercise` est en `SET NULL` et `LoggedExercise.exerciseName`
 *    garde une copie figée du nom au moment de la séance : c'est ce `$fallback`.
 *
 * ## Pourquoi le nom vivant prime sur la copie figée
 *
 * L'historique **affiche l'exercice de la bibliothèque tant qu'il existe**, pas
 * son snapshot. Sans ça, une séance faite avant la bascule resterait écrite en
 * français au milieu d'un écran anglais, et le snapshot ne pourrait de toute
 * façon jamais suivre les deux langues sans une seconde colonne.
 *
 * Conséquence assumée : renommer un exercice change son libellé dans
 * l'historique. C'est le comportement voulu — le snapshot n'existe que pour
 * survivre à une suppression, pas pour figer un affichage.
 *
 * ## La mémoïsation
 *
 * `label()` est appelé une fois par ligne d'une séance, d'un plan, d'un export :
 * la préférence se lit une seule fois par requête. `Security::getUser()` touche
 * la session à chaque appel, et une trame de douze semaines en ferait des
 * centaines.
 */
final class ExerciseNaming
{
    private ?ExerciseLanguage $language = null;

    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Le libellé à afficher pour un exercice, dans la langue de l'utilisateur.
     *
     * @param string|null $fallback la copie figée du nom, quand on en a une
     *                              (`LoggedExercise.exerciseName`)
     */
    public function label(?Exercise $exercise, ?string $fallback = null): string
    {
        if (null === $exercise) {
            return null !== $fallback && '' !== $fallback ? $fallback : '—';
        }

        return $this->labelIn($exercise, $this->language());
    }

    /**
     * Le libellé dans une langue imposée, hors contexte de requête.
     *
     * Sert aux écritures qui ne s'adressent pas à l'utilisateur courant — un
     * export, un flux, un payload d'API — et aux tests, qui ne veulent pas
     * monter une session pour vérifier un repli.
     */
    public function labelIn(Exercise $exercise, ExerciseLanguage $language): string
    {
        $name = (string) $exercise->getName();

        if (ExerciseLanguage::EN !== $language) {
            return $name;
        }

        $nameEn = $exercise->getNameEn();

        return null !== $nameEn && '' !== $nameEn ? $nameEn : $name;
    }

    /**
     * Le second libellé, celui que la langue courante n'affiche pas — `null`
     * quand il n'y en a pas d'autre à montrer.
     *
     * N'entre dans aucune vue de consultation : sert à la **recherche**, où les
     * deux langues doivent trouver la même carte, et à l'édition, où l'on veut
     * voir ce qu'on est en train de nommer.
     */
    public function alternate(?Exercise $exercise): ?string
    {
        if (null === $exercise) {
            return null;
        }

        $name = (string) $exercise->getName();
        $nameEn = $exercise->getNameEn();

        if (null === $nameEn || '' === $nameEn || $nameEn === $name) {
            return null;
        }

        return ExerciseLanguage::EN === $this->language() ? $name : $nameEn;
    }

    /**
     * Le texte sur lequel une palette cherche : les deux noms, quoi qu'il
     * arrive.
     *
     * Volontairement **pas normalisé ici**. La normalisation est faite côté
     * client par `assets/search.js`, qui doit de toute façon normaliser la
     * requête tapée : la faire deux fois, une fois de chaque côté, ferait
     * diverger les deux écritures au premier caractère exotique.
     */
    public function searchText(Exercise $exercise): string
    {
        $parts = [(string) $exercise->getName()];
        $nameEn = $exercise->getNameEn();

        if (null !== $nameEn && '' !== $nameEn && $nameEn !== $parts[0]) {
            $parts[] = $nameEn;
        }

        return implode(' ', $parts);
    }

    public function language(): ExerciseLanguage
    {
        if (null !== $this->language) {
            return $this->language;
        }

        $user = $this->security->getUser();

        return $this->language = $user instanceof User
            ? $user->getExerciseLanguage()
            : ExerciseLanguage::FR;
    }
}
