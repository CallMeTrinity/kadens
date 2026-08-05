<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qu'une pierre tombale (`DeletedEntity`) peut désigner.
 *
 * Deux types, et pas un de plus : ce sont exactement les deux choses que la base
 * locale du mobile matérialise en propre (KL-14). Le réalisé (`LoggedExercise`,
 * `LoggedSet`) n'en fait pas partie — il voyage toujours **dans** sa séance
 * datée, que le client remplace en entier : une pierre tombale par série serait
 * du bruit pour une information que le document porte déjà.
 */
enum DeletedEntityType: string
{
    case EXERCISE = 'exercise';
    case SCHEDULED_WORKOUT = 'scheduled_workout';
}
