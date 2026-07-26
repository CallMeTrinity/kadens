<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Cycle de vie d'une relation coach ↔ athlète. La demande part d'un côté ou de
 * l'autre (cf. `Coaching::requestedBy`) et seul le destinataire tranche.
 *
 * Seul ACCEPTED ouvre des droits : les voters n'accordent l'accès croisé au
 * contenu que sur ce statut. DECLINED et ENDED sont conservés (pas de suppression
 * de ligne) pour garder une trace et éviter le harcèlement par re-demande.
 */
enum CoachingStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case ENDED = 'ended';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::ACCEPTED => 'Active',
            self::DECLINED => 'Refusée',
            self::ENDED => 'Terminée',
        };
    }

    /**
     * Modificateur CSS réutilisant les tokens de statut (done/planned/missed),
     * comme GoalOutcome : la couleur porte l'état, pas une nouvelle famille.
     */
    public function modifier(): string
    {
        return match ($this) {
            self::PENDING => 'planned',
            self::ACCEPTED => 'done',
            self::DECLINED, self::ENDED => 'missed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'lucide:clock',
            self::ACCEPTED => 'lucide:user-check',
            self::DECLINED => 'lucide:user-x',
            self::ENDED => 'lucide:x',
        };
    }

    /** Seul statut qui accorde des droits croisés (voters). */
    public function isActive(): bool
    {
        return self::ACCEPTED === $this;
    }
}
