<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qu'on mesure sur un record de la fiche athlète. Trois questions, trois
 * lectures du réalisé, et elles ne se départagent pas de la même façon.
 *
 * - `LOAD` : la charge la plus lourde soulevée. Un mouvement lesté.
 * - `REPS` : le plus grand nombre de répétitions **au poids du corps**. Une
 *   série lestée en est exclue par construction : 8 dips à 15 kg ne disent rien
 *   d'un maximum de répétitions, et les laisser entrer ferait gagner le record
 *   à celui qui s'est chargé.
 * - `HOLD` : le temps le plus long tenu. Un gainage, une suspension.
 *
 * Un même exercice peut répondre à deux d'entre elles (une traction se leste ou
 * se compte), d'où deux cases distinctes plutôt qu'un record qui changerait de
 * nature selon la dernière série faite.
 */
enum RecordMetric: string
{
    case LOAD = 'load';
    case REPS = 'reps';
    case HOLD = 'hold';
}
