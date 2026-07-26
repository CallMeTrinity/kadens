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
     * Sigle de la pastille de type (1 à 2 lettres), affiché dans les listes de
     * séries. Vide pour NORMAL : pas de pastille pour une série ordinaire.
     * D et DS sont volontairement distincts (dégressive ≠ drop set).
     */
    public function letter(): string
    {
        return match ($this) {
            self::WARMUP => 'W',
            self::NORMAL => '',
            self::DEGRESSIVE => 'D',
            self::TO_FAILURE => 'F',
            self::DROP_SET => 'DS',
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
