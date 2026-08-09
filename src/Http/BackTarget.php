<?php

namespace App\Http;

/**
 * Ancre de contexte de navigation, portée par `?from=` dans l'URL.
 *
 * Le problème qu'elle résout : une séance s'ouvre depuis six endroits (la
 * bibliothèque, un plan, l'éditeur de plan, le calendrier, une fiche athlète, un
 * objectif) et n'avait qu'un seul retour, écrit en dur vers l'index. Cinq fois
 * sur six il mentait — et depuis l'éditeur de plan il menait à une impasse, la
 * copie locale (`planLocal = true`) étant exclue de la bibliothèque.
 *
 * Le jeton dit **d'où l'on vient**, pas où aller : c'est le conteneur d'origine,
 * pas l'écran précédent. Il survit donc aux sauts internes (séance → composer),
 * là où une pile d'historique imposerait de remonter maillon par maillon.
 *
 * Deux invariants à ne pas casser :
 *
 * 1. **Le jeton ne porte jamais d'URL, seulement un type et une clé.** La
 *    destination est reconstruite par `path()` à partir d'une table fermée de
 *    routes — il n'y a donc aucune redirection ouverte possible, quoi qu'on
 *    écrive dans la query.
 * 2. **Un jeton illisible n'est pas une erreur, c'est une absence.** `parse()`
 *    rend `null` et l'appelant retombe sur son retour d'origine. Une URL
 *    tronquée, recopiée à la main ou vieillie ne doit jamais produire de page
 *    cassée : le pire cas est le comportement d'avant cette fonctionnalité.
 *
 * Conséquence à connaître côté PWA : `/workout/12` et `/workout/12?from=plan-3`
 * sont deux URLs pour une même page. Le service worker doit apparier en
 * ignorant la query, sinon le cache offline se fragmente par contexte d'entrée.
 */
final class BackTarget
{
    public const PLAN = 'plan';
    public const PLAN_EDIT = 'plan-edit';
    public const CAL_WEEK = 'cal-week';
    public const CAL_MONTH = 'cal-month';

    private function __construct(
        public readonly string $kind,
        public readonly string $value,
    ) {
    }

    /**
     * Lit un jeton brut. Rend `null` dès que quoi que ce soit cloche — type
     * inconnu, identifiant non numérique, date impossible.
     */
    public static function parse(?string $raw): ?self
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        // `plan-edit` avant `plan` : sur `plan-edit-12`, l'alternance doit tenter
        // le type le plus long d'abord, sinon elle part sur `plan` et backtracke.
        if (1 === preg_match('/^(plan-edit|plan)-(\d+)$/', $raw, $m)) {
            return new self($m[1], $m[2]);
        }

        if (1 === preg_match('/^cal-week-(\d{4}-\d{2}-\d{2})$/', $raw, $m)) {
            return self::isRealDate($m[1], 'Y-m-d') ? new self(self::CAL_WEEK, $m[1]) : null;
        }

        if (1 === preg_match('/^cal-month-(\d{4}-\d{2})$/', $raw, $m)) {
            return self::isRealDate($m[1], 'Y-m') ? new self(self::CAL_MONTH, $m[1]) : null;
        }

        return null;
    }

    /**
     * Fabrique le jeton d'un contexte. Seul endroit qui connaît le format :
     * un template qui écrirait `'plan-' ~ id` à la main le figerait ici.
     */
    public static function token(string $kind, string|int $value): string
    {
        return $kind.'-'.$value;
    }

    /**
     * La regex laisse passer `2026-13-45` : le format est bon, la date n'existe
     * pas. Sans ce filtre, la route se génèrerait (son requirement est le même
     * `\d{4}-\d{2}-\d{2}`) et c'est le calendrier qui planterait à l'arrivée.
     */
    private static function isRealDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);

        return false !== $date && $date->format($format) === $value;
    }
}
