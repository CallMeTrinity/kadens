<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\StatsRange;

/**
 * Une fenêtre de statistiques résolue en bornes de dates : ce que le sélecteur
 * de la page `/profile/stats` produit, et la seule chose que TrainingStats
 * accepte en entrée.
 *
 * Trois décisions portées ici :
 *
 * - **`start` nullable, `end` jamais.** « Depuis le début » n'a pas de borne
 *   basse, et lui en inventer une (la date du premier compte, une constante)
 *   ferait mentir les requêtes le jour où de l'historique est importé plus
 *   ancien. Les repositories reçoivent donc un `?start` et n'ajoutent leur
 *   clause `>=` que s'il existe.
 * - **Bornes en jours pleins, jamais en instants.** `ScheduledWorkout` porte
 *   une DATE, pas un datetime : comparer à `maintenant` écarterait les séances
 *   d'aujourd'hui après-midi. `end` est donc toujours une fin de journée.
 * - **Un mois est une fenêtre comme une autre.** MONTH n'a pas de traitement
 *   séparé en aval : il produit un `start`/`end` et un libellé, c'est tout.
 *
 * La lecture de la valeur brute d'URL est ici et nulle part ailleurs : une
 * valeur inconnue retombe silencieusement sur la fenêtre par défaut plutôt que
 * de lever — un lien périmé doit afficher des stats, pas une 404.
 */
final readonly class StatsPeriod
{
    /**
     * Noms de mois en français. Dupliqués depuis CalendarController à dessein :
     * le sien est privé, et l'app n'a pas d'intl (le mutualisé ne garantit pas
     * `ext-intl`, cf. le choix du writer SVG pour le QR).
     */
    private const MONTH_NAMES = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    private function __construct(
        public StatsRange $range,
        public ?\DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public string $label,
        /** Valeur à remettre dans l'URL (`4w`, `6m`, `all` ou `2026-07`). */
        public string $queryValue,
    ) {
    }

    /**
     * Résout la valeur brute de `?range=`. Accepte les trois clés d'enum et un
     * mois au format `YYYY-MM` ; tout le reste retombe sur la fenêtre par
     * défaut (4 semaines), y compris `null`.
     */
    public static function resolve(?string $raw, ?\DateTimeImmutable $now = null): self
    {
        $now = ($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $raw = null !== $raw ? trim($raw) : null;

        if (null === $raw || '' === $raw) {
            return self::fourWeeks($now);
        }

        // Un mois se reconnaît à sa forme, pas à un préfixe : `2026-07` EST la
        // valeur d'URL, il n'y a pas de second paramètre à tenir d'accord.
        if (1 === preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
            $month = (int) $m[2];
            if ($month >= 1 && $month <= 12) {
                return self::month((int) $m[1], $month);
            }

            return self::fourWeeks($now);
        }

        return match (StatsRange::tryFrom($raw)) {
            StatsRange::SIX_MONTHS => self::sixMonths($now),
            StatsRange::ALL => self::allTime($now),
            default => self::fourWeeks($now),
        };
    }

    public static function fourWeeks(?\DateTimeImmutable $now = null): self
    {
        $end = ($now ?? new \DateTimeImmutable())->setTime(23, 59, 59);

        return new self(
            StatsRange::FOUR_WEEKS,
            $end->modify('-27 days')->setTime(0, 0),
            $end,
            '4 dernières semaines',
            StatsRange::FOUR_WEEKS->value,
        );
    }

    public static function sixMonths(?\DateTimeImmutable $now = null): self
    {
        $end = ($now ?? new \DateTimeImmutable())->setTime(23, 59, 59);

        return new self(
            StatsRange::SIX_MONTHS,
            $end->modify('-6 months')->modify('+1 day')->setTime(0, 0),
            $end,
            '6 derniers mois',
            StatsRange::SIX_MONTHS->value,
        );
    }

    public static function allTime(?\DateTimeImmutable $now = null): self
    {
        $end = ($now ?? new \DateTimeImmutable())->setTime(23, 59, 59);

        return new self(StatsRange::ALL, null, $end, 'Depuis le début', StatsRange::ALL->value);
    }

    public static function month(int $year, int $month): self
    {
        $first = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->setTime(0, 0);

        return new self(
            StatsRange::MONTH,
            $first,
            $first->modify('last day of this month')->setTime(23, 59, 59),
            self::MONTH_NAMES[$month].' '.$year,
            $first->format('Y-m'),
        );
    }

    /**
     * Le mois affiché, ou null hors fenêtre mensuelle. Sert au `<select>` à
     * savoir quelle option marquer.
     */
    public function monthKey(): ?string
    {
        return StatsRange::MONTH === $this->range ? $this->queryValue : null;
    }

    /**
     * Libellé d'un mois pour le sélecteur (« juillet 2026 »).
     */
    public static function monthLabel(\DateTimeImmutable $month): string
    {
        return self::MONTH_NAMES[(int) $month->format('n')].' '.$month->format('Y');
    }

    /**
     * Nombre de jours couverts par la fenêtre, au moins 1.
     *
     * `$firstActivity` ne sert qu'à la fenêtre « depuis le début », qui n'a pas
     * de borne basse : sa durée est celle de l'historique réel, pas celle d'un
     * compte créé il y a trois ans et resté vide deux. Sans historique, la
     * fenêtre vaut un jour — un dénominateur, jamais zéro.
     */
    public function dayCount(?\DateTimeImmutable $firstActivity = null): int
    {
        $start = $this->start ?? $firstActivity;
        if (null === $start) {
            return 1;
        }

        $days = (int) $start->setTime(0, 0)->diff($this->end->setTime(0, 0))->days;

        return max(1, $days + 1);
    }

    /**
     * La fenêtre a-t-elle une borne basse ? Faux pour « depuis le début »
     * seulement — c'est ce que les requêtes testent avant d'ajouter leur clause.
     */
    public function isBounded(): bool
    {
        return null !== $this->start;
    }
}
