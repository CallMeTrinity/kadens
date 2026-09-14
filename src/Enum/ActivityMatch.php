<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce que le rapprochement a fait d'une activité importée (`ActivityMatcher`).
 * La distinction entre « aucune candidate » et « plusieurs » n'est pas
 * cosmétique : seule la première autorise à créer une séance libre.
 */
enum ActivityMatch: string
{
    /** Rattachée à la seule séance prévue ou faite qui lui correspondait. */
    case ATTACHED = 'attached';

    /** Aucune séance prévue : une séance libre a été créée pour la porter. */
    case FREE_SESSION = 'free_session';

    /** Plusieurs candidates : on ne devine pas, elle attend un geste manuel. */
    case AMBIGUOUS = 'ambiguous';

    /** Aucune candidate, et pas de séance libre demandée. */
    case NO_CANDIDATE = 'no_candidate';

    /** Type non reconnu ou déjà rattachée : rien à rapprocher. */
    case NOT_MATCHABLE = 'not_matchable';
}
