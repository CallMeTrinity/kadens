<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ce qu'une synchronisation a fait, pour le dire en une phrase après le clic
 * (ou en tableau à la fin de la commande d'historique).
 */
final readonly class SyncReport
{
    public function __construct(
        public int $imported,
        public int $attached,
        /** Activités vues mais inexploitables (arrivées via Strava, sans identifiant…). */
        public int $skipped,
        /** Activités nouvelles laissées pour le prochain passage (lot borné ou panne). */
        public int $remaining,
        /** Séances libres créées pour des activités sans séance prévue (historique seulement). */
        public int $freeSessions = 0,
        /** Activités laissées à rattacher parce que plusieurs séances pouvaient leur correspondre. */
        public int $ambiguous = 0,
    ) {
    }

    public function summary(): string
    {
        if (0 === $this->imported && 0 === $this->remaining) {
            $sentence = 'Aucune nouvelle activité.';
        } else {
            $sentence = \sprintf(
                '%d activité%s importée%s, %d rattachée%s à une séance.',
                $this->imported,
                $this->imported > 1 ? 's' : '',
                $this->imported > 1 ? 's' : '',
                $this->attached,
                $this->attached > 1 ? 's' : '',
            );
        }

        if ($this->remaining > 0) {
            $sentence .= \sprintf(' %d restante%s : synchronise à nouveau pour continuer.', $this->remaining, $this->remaining > 1 ? 's' : '');
        }

        if ($this->skipped > 0) {
            // Presque toujours une activité arrivée dans Intervals par Strava, que
            // son API n'expose pas : le dire évite de chercher une panne.
            $sentence .= \sprintf(' %d ignorée%s : Intervals ne transmet pas les activités reçues via Strava.', $this->skipped, $this->skipped > 1 ? 's' : '');
        }

        return $sentence;
    }
}
