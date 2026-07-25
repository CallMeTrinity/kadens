<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduledStatus: string
{
    case PLANNED = 'planned';
    case DONE = 'done';
    case MISSED = 'missed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PLANNED => 'Prévue',
            self::DONE => 'Faite',
            self::MISSED => 'Manquée',
        };
    }

    /**
     * Statut suivant dans le cycle rapide (clic sur la zone gauche d'une séance
     * au calendrier) : prévue → faite → manquée → prévue. Boucle volontairement
     * pour rester réversible d'un seul geste.
     */
    public function next(): self
    {
        return match ($this) {
            self::PLANNED => self::DONE,
            self::DONE => self::MISSED,
            self::MISSED => self::PLANNED,
        };
    }
}
