<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduledWorkout;
use App\Enum\ActivityType;

/**
 * Ce que l'app mobile a le droit de voir de la fenêtre du bootstrap (KL-14).
 *
 * La règle du projet est « **le réalisé se logue en muscu, jamais en cardio** »
 * (`CLAUDE.md` §3). Elle décrivait jusqu'ici ce que le téléphone *écrit* ; elle
 * décide désormais aussi de ce qu'il *reçoit*. Une sortie course descendue sur le
 * téléphone n'était pas neutre : elle occupait l'écran « Aujourd'hui », proposait
 * « Démarrer » pour une séance qui ne se consigne pas, et pouvait rafler l'unique
 * action primaire du jour à la séance de force qui la méritait.
 *
 * **Le filtre est ici et nulle part ailleurs.** Le téléphone ne porte aucune
 * activité sur `scheduled_workout` — elle ne vit que dans le prescrit, que la
 * liste du jour refuse de charger par principe (`db/schema.ts`) — donc lui faire
 * trancher aurait demandé une colonne de plus et un second endroit à tenir
 * d'accord avec celui-ci. Le serveur, lui, a déjà les exercices hydratés :
 * l'appel ne coûte pas une requête.
 *
 * ## Les trois cas, dans cet ordre
 *
 * 1. **Une séance qui porte du réalisé descend toujours.** C'est le garde-fou qui
 *    compte : la fenêtre du bootstrap **fait autorité** (`docs/api-mobile.md`
 *    §4.5), ce qu'elle ne contient pas est effacé côté téléphone. Sans cette
 *    ligne, retirer le dernier exercice de muscu d'une séance déjà faite
 *    supprimerait le réalisé de l'historique local. Ce qui a été fait ne
 *    disparaît pas.
 * 2. **Une séance sans prescrit descend toujours.** Séance libre créée sur le
 *    téléphone, coquille encore vide posée sur le web : il n'y a rien à écarter,
 *    et c'est précisément une séance qu'on va garnir barre en main (KL-34).
 * 3. **Sinon, il faut au moins un exercice d'activité `gym`.** C'est la
 *    définition de « muscu » déjà tenue par `WorkoutMetrics::volume()` pour le
 *    tonnage — une seule définition dans le projet, pas deux. Une séance mixte
 *    (renforcement puis footing de retour au calme) descend donc, avec son cardio
 *    en lecture ; une séance qui n'a aucune ligne de renforcement, non.
 *
 * Conséquence assumée : une séance 100 % mobilité n'est pas visible sur le
 * téléphone. C'est ce que « aucun exercice gym » veut dire, et changer d'avis se
 * fait en une ligne ici.
 */
final class TrackableSchedule
{
    /**
     * La séance datée a-t-elle sa place dans l'app de suivi ?
     */
    public function includes(ScheduledWorkout $scheduled): bool
    {
        if ($scheduled->hasLog()) {
            return true;
        }

        $workout = $scheduled->getWorkout();
        if (null === $workout) {
            return true;
        }

        $empty = true;

        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $empty = false;

                if (ActivityType::GYM === $prescribed->getExercise()?->getActivity()) {
                    return true;
                }
            }
        }

        // Un prescrit sans aucune ligne : cas 2, on ne prive de rien.
        return $empty;
    }
}
