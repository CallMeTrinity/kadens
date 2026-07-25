<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Résultat d'un objectif, renseigné APRÈS l'échéance. Nullable tant que l'objectif
 * n'est pas passé (ou pas encore débriefé). Boucle « prévu vs réalisé » au niveau
 * de l'objectif, pas de la séance : a-t-on atteint le but visé ?
 */
enum GoalOutcome: string
{
    case ACHIEVED = 'achieved';
    case PARTIAL = 'partial';
    case MISSED = 'missed';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACHIEVED => 'Atteint',
            self::PARTIAL => 'Partiellement atteint',
            self::MISSED => 'Manqué',
        };
    }

    /**
     * Modificateur CSS réutilisant les tokens de statut (done/planned/missed).
     */
    public function modifier(): string
    {
        return match ($this) {
            self::ACHIEVED => 'done',
            self::PARTIAL => 'planned',
            self::MISSED => 'missed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ACHIEVED => 'lucide:circle-check',
            self::PARTIAL => 'lucide:circle-dot',
            self::MISSED => 'lucide:x',
        };
    }
}
