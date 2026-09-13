<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedActivity;
use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;
use App\Repository\ImportedActivityRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Rattache une activité importée à la séance datée dont elle est le réalisé,
 * **seulement quand il n'y a aucun doute**.
 *
 * Une candidate, c'est une séance du même propriétaire, le même jour local,
 * prévue ou faite, sans activité déjà rattachée, dont le prescrit contient
 * l'activité (une sortie vélo ne rattache pas une séance course). **Une seule
 * candidate : on rattache. Zéro ou plusieurs : on ne devine pas**, l'activité
 * reste « à rattacher » et l'utilisateur choisit depuis la page de la séance.
 * Un rattachement faux est pire qu'un rattachement manquant : il déplace un
 * chiffre dans les statistiques sans que rien ne le signale.
 *
 * Rattacher passe une séance **prévue** en **faite** : une activité enregistrée
 * est la preuve que la séance a eu lieu. L'inverse n'est pas vrai, détacher ne
 * rétablit rien.
 */
final class ActivityMatcher
{
    /**
     * Séances rattachées pendant ce lot, pas encore en base : sans ce registre,
     * deux activités du même jour se disputeraient la même candidate.
     *
     * @var array<int, true>
     */
    private array $claimed = [];

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduledWorkouts,
        private readonly ImportedActivityRepository $activities,
        private readonly WorkoutMetrics $metrics,
    ) {
    }

    public function autoAttach(ImportedActivity $activity): ?ScheduledWorkout
    {
        $type = $activity->getActivity();

        if (null === $type || null !== $activity->getScheduledWorkout()) {
            return null;
        }

        $sameDay = $this->scheduledWorkouts->findPlannedOrDoneWithContentForOwnerOn($activity->getOwner(), $activity->getLocalDate());

        $matching = array_values(array_filter(
            $sameDay,
            fn (ScheduledWorkout $s): bool => null !== $s->getWorkout()
                && !isset($this->claimed[(int) $s->getId()])
                && \in_array($type, $this->metrics->distinctActivities($s->getWorkout()), true),
        ));

        $taken = $this->activities->scheduledIdsWithActivity(array_map(static fn (ScheduledWorkout $s): int => (int) $s->getId(), $matching));
        $candidates = array_values(array_filter($matching, static fn (ScheduledWorkout $s): bool => !\in_array((int) $s->getId(), $taken, true)));

        if (1 !== \count($candidates)) {
            return null;
        }

        $this->attach($activity, $candidates[0]);
        $this->claimed[(int) $candidates[0]->getId()] = true;

        return $candidates[0];
    }

    /** Le rattachement lui-même, commun à l'automatique et au geste manuel. */
    public function attach(ImportedActivity $activity, ScheduledWorkout $scheduled): void
    {
        $activity->setScheduledWorkout($scheduled);

        if (ScheduledStatus::PLANNED === $scheduled->getStatus()) {
            $scheduled->setStatus(ScheduledStatus::DONE);
        }
    }

    /** À appeler entre deux lots indépendants : le registre ne vaut que pour un import. */
    public function reset(): void
    {
        $this->claimed = [];
    }
}
