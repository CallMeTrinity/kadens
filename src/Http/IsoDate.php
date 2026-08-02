<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Ce que l'API accepte comme date venue d'un client, et **la seule** définition
 * de cette question.
 *
 * Le garde-fou de forme n'est pas décoratif : le constructeur de
 * `DateTimeImmutable` accepte aussi l'anglais relatif (« yesterday », « now »,
 * « +3 days »), la chaîne vide, et lit `31/07/2026` comme une date américaine
 * invalide plutôt que comme une erreur. Un client qui enverrait n'importe quoi
 * obtiendrait alors une donnée silencieusement fausse — plausible, et fausse —
 * là où un 400 ou un 422 se voit tout de suite.
 *
 * Les motifs sont exposés en constantes parce qu'ils servent à deux endroits qui
 * ne peuvent pas partager le même code : `Assert\Regex` sur les charges utiles
 * entrantes (KL-16), et la validation manuelle d'un paramètre de requête
 * (`?since` du bootstrap, KL-14). Le motif, lui, doit rester unique.
 */
final class IsoDate
{
    /** Une date seule : `2026-08-02`. La validité du jour reste l'affaire d'`Assert\Date`. */
    public const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Une date-heure : `2026-08-02T18:30:00+02:00`, `…Z`, ou sans secondes. Le
     * décalage n'est pas imposé — exiger le format ATOM au caractère près
     * refuserait le `Z` que la moitié des clients écrivent.
     */
    public const string DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/';

    /**
     * Une date-heure ISO 8601, ou **null** si la chaîne n'en est pas une. Null
     * pour une entrée nulle aussi : l'absence n'est pas une erreur, c'est à
     * l'appelant de dire si le champ était requis.
     */
    public static function dateTime(?string $raw): ?\DateTimeImmutable
    {
        return self::parse($raw, self::DATE_TIME_PATTERN);
    }

    /** Une date seule, à minuit. Mêmes règles que ci-dessus. */
    public static function date(?string $raw): ?\DateTimeImmutable
    {
        return self::parse($raw, self::DATE_PATTERN)?->setTime(0, 0);
    }

    private static function parse(?string $raw, string $pattern): ?\DateTimeImmutable
    {
        if (null === $raw || 1 !== preg_match($pattern, $raw)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
