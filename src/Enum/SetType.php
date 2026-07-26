<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'une série individuelle d'un exercice de force (mode « séries détaillées »
 * de PrescribedExercise). Permet d'exprimer une prescription hétérogène :
 * échauffement montant, séries de travail, dégressif, série à l'échec, drop set.
 *
 * Cohérent avec l'esprit « planification, pas tracking » : on planifie l'INTENTION
 * de chaque série (« ici un drop set »), le détail exact se vit à la salle.
 *
 * NORMAL est la valeur par défaut (une série de travail ordinaire).
 */
enum SetType: string
{
    case WARMUP = 'warmup';
    case NORMAL = 'normal';
    case DEGRESSIVE = 'degressive';
    case TO_FAILURE = 'to_failure';
    case DROP_SET = 'drop_set';

    public function getLabel(): string
    {
        return match ($this) {
            self::WARMUP => 'Échauffement',
            self::NORMAL => 'Normale',
            self::DEGRESSIVE => 'Dégressive',
            self::TO_FAILURE => 'À l\'échec',
            self::DROP_SET => 'Drop set',
        };
    }

    /**
     * Libellé court pour les résumés/badges. Vide pour NORMAL : une série de
     * travail ordinaire n'a pas besoin d'être qualifiée, ça allège la lecture.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::WARMUP => 'Échauf',
            self::NORMAL => '',
            self::DEGRESSIVE => 'Dégressive',
            self::TO_FAILURE => 'À l\'échec',
            self::DROP_SET => 'Drop set',
        };
    }

    /**
     * Icône Lucide du badge de type. NORMAL n'a pas d'icône (pas de badge affiché).
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::WARMUP => 'lucide:flame',
            self::NORMAL => null,
            self::DEGRESSIVE => 'lucide:trending-down',
            self::TO_FAILURE => 'lucide:zap',
            self::DROP_SET => 'lucide:chevrons-down',
        };
    }

    /**
     * La série compte-t-elle comme volume de travail effectif ? L'échauffement est
     * exclu du tonnage et du décompte de séries par groupe musculaire (ce n'est pas
     * du volume de travail). Toutes les autres séries comptent.
     */
    public function countsAsWorking(): bool
    {
        return self::WARMUP !== $this;
    }
}
