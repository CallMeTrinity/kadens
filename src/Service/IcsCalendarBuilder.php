<?php

namespace App\Service;

use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;

/**
 * Construit un flux iCalendar (RFC 5545) à partir de séances datées, pour
 * l'abonnement calendrier (Google Agenda, Apple Calendar…).
 *
 * Choix structurant : les séances n'ont qu'une DATE (pas d'heure), donc des
 * événements « journée entière » (VALUE=DATE) — aucun VTIMEZONE, aucun casse-tête
 * de fuseau. Le contenu détaillé de la séance (blocs / exercices) est produit par
 * PlanFlattener (source unique de mise à plat, jamais réimplémentée ici) et posé
 * dans la DESCRIPTION, lisible depuis le téléphone.
 *
 * Le rendu respecte les contraintes du format : fins de ligne CRLF, pliage des
 * lignes à 75 octets (sans couper un caractère UTF-8), échappement de `,;\` et des
 * sauts de ligne. UID stable par séance datée → Google met à jour au lieu de
 * dupliquer.
 */
final class IcsCalendarBuilder
{
    public function __construct(
        private readonly PlanFlattener $planFlattener,
    ) {
    }

    /**
     * @param list<ScheduledWorkout> $scheduledWorkouts
     */
    public function build(array $scheduledWorkouts, string $calendarName): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kadens//Calendrier d\'entrainement//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'X-WR-TIMEZONE:Europe/Paris',
        ];

        // DTSTAMP commun : instant de génération du flux (UTC), obligatoire par événement.
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        foreach ($scheduledWorkouts as $scheduled) {
            foreach ($this->event($scheduled, $stamp) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * @return list<string>
     */
    private function event(ScheduledWorkout $scheduled, string $stamp): array
    {
        $date = $scheduled->getScheduledDate();
        $workout = $scheduled->getWorkout();

        // Événement journée entière : DTEND est exclusif (le lendemain).
        $start = $date->format('Ymd');
        $end = $date->modify('+1 day')->format('Ymd');

        // Le statut se lit dans le titre (VEVENT.STATUS ne code que
        // CONFIRMED/TENTATIVE/CANCELLED, pas « fait » ; le préfixe est plus parlant).
        $prefix = match ($scheduled->getStatus()) {
            ScheduledStatus::DONE => '✓ ',
            ScheduledStatus::MISSED => '✗ ',
            default => '',
        };

        $lines = [
            'BEGIN:VEVENT',
            'UID:sw-'.$scheduled->getId().'@kadens.antoninpamart.fr',
            'DTSTAMP:'.$stamp,
            'DTSTART;VALUE=DATE:'.$start,
            'DTEND;VALUE=DATE:'.$end,
            'SUMMARY:'.$this->escape($prefix.(string) $workout->getTitle()),
        ];

        $description = $this->describe($scheduled);
        if ('' !== $description) {
            $lines[] = 'DESCRIPTION:'.$this->escape($description);
        }

        // Séance = repère dans la journée, pas un créneau bloquant.
        $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Description texte d'une séance : durée estimée, puis chaque bloc (rôle, tours)
     * et ses exercices (nom + résumé lisible), enfin la note d'écart si présente.
     * Les sauts de ligne réels sont convertis en `\n` iCal par escape().
     */
    private function describe(ScheduledWorkout $scheduled): string
    {
        $workout = $scheduled->getWorkout();
        $flat = $this->planFlattener->flattenWorkout($workout);

        $parts = [];
        if (null !== $workout->getEstimatedDurationMinutes()) {
            $parts[] = 'Durée estimée : '.$workout->getEstimatedDurationMinutes().' min';
        }

        foreach ($flat['blocks'] as $flatBlock) {
            $block = $flatBlock['block'];
            $header = $block->getRole()?->getLabel() ?? 'Bloc';
            if (null !== $block->getLabel() && '' !== $block->getLabel()) {
                $header .= ' — '.$block->getLabel();
            }
            if (($rounds = $block->getRounds()) && $rounds > 1) {
                $header .= ' (×'.$rounds.')';
            }
            $parts[] = "\n".$header;

            foreach ($flatBlock['exercises'] as $flatEx) {
                $name = $flatEx['exercise']?->getName() ?? 'Exercice';
                // Rang de superset en tête (« A1 »), pour que l'enchaînement se
                // lise aussi dans un agenda, où il n'y a aucune mise en forme.
                if (null !== $flatEx['groupLabel']) {
                    $name = $flatEx['groupLabel'].' '.$name;
                }
                $summary = $flatEx['summary'];
                $parts[] = '' !== $summary ? '· '.$name.' : '.$summary : '· '.$name;
            }
        }

        if (null !== $scheduled->getCompletionNotes() && '' !== trim($scheduled->getCompletionNotes())) {
            $parts[] = "\nNote : ".trim($scheduled->getCompletionNotes());
        }

        return implode("\n", $parts);
    }

    /**
     * Échappe une valeur texte iCal : backslash d'abord (pour ne pas ré-échapper
     * ceux qu'on ajoute ensuite), puis `,` et `;`, enfin les sauts de ligne réels
     * en `\n` littéral.
     */
    private function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([',', ';'], ['\\,', '\\;'], $value);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * Plie une ligne logique à 75 octets max (RFC 5545), les suites commençant par
     * une espace. On découpe sur les caractères UTF-8 (jamais au milieu d'un
     * multi-octets, sinon accents/emoji corrompus).
     */
    private function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }

        $segments = [];
        $current = '';
        $currentBytes = 0;
        foreach (mb_str_split($line) as $char) {
            $charBytes = \strlen($char);
            // 75 octets pour la 1re ligne ; 74 pour les suites (l'espace de tête compte).
            $limit = [] === $segments ? 75 : 74;
            if ($currentBytes + $charBytes > $limit) {
                $segments[] = $current;
                $current = $char;
                $currentBytes = $charBytes;
            } else {
                $current .= $char;
                $currentBytes += $charBytes;
            }
        }
        $segments[] = $current;

        return implode("\r\n ", $segments);
    }
}
