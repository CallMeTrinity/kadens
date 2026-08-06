<?php

declare(strict_types=1);

namespace App\Service;

/**
 * La forme comparable d'un libellé : minuscules, sans accents, réduit aux
 * alphanumériques séparés par des espaces simples.
 *
 * C'est la **seule** définition de « ces deux textes désignent la même chose »
 * du projet côté PHP. Elle était née privée dans `ImportedExerciseMap` pour
 * apparier des exports Blast/FitNotes ; elle sert maintenant aussi à
 * `app:import-exercises` (adopter une ligne existante par son nom avant qu'elle
 * ne porte une `refKey`) et à la construction du texte cherché des palettes.
 *
 * Son **pendant JS vit dans `assets/search.js`** : les deux doivent produire la
 * même chaîne pour la même entrée, sans quoi une recherche trouverait à l'écran
 * ce que l'import considère comme un autre exercice. Les deux écritures bougent
 * ensemble.
 *
 * `Any-Latin; Latin-ASCII` plutôt qu'une table d'accents à la main : la
 * translittération ICU couvre les ligatures (`œ` → `oe`) et les caractères que
 * personne ne pense à lister.
 *
 * Le service est sans état : les méthodes sont statiques pour rester appelables
 * depuis un contexte qui n'a rien à injecter (fixture, commande, comparateur de
 * tri), et l'autowiring reste possible pour le reste.
 */
final class TextNormalizer
{
    /** Les mots-outils français, sans information de mouvement. */
    private const array STOP_WORDS = ['a', 'au', 'aux', 'de', 'des', 'du', 'la', 'le', 'les', 'en', 'sur', 'avec'];

    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /**
     * Les mots signifiants d'un libellé, mots-outils retirés.
     *
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $words = preg_split('/\s+/', self::normalize($value), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff($words, self::STOP_WORDS));
    }

    /**
     * Un identifiant en kebab-case ASCII, dérivé d'un libellé.
     *
     * Sert à proposer une `Exercise.refKey` quand on en crée une à la main. Ce
     * n'est **pas** un `SlugGenerator` : il n'y a ni unicité ni suffixe
     * numérique ici, et surtout aucune régénération — une `refKey` posée ne
     * change plus, même si le nom dont elle dérivait change.
     */
    public static function slug(string $value): string
    {
        return str_replace(' ', '-', self::normalize($value));
    }
}
