<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedActivity;
use App\Entity\ScheduledWorkout;
use App\Enum\ActivityMatch;
use App\Enum\ScheduledStatus;
use App\Repository\ImportedActivityRepository;
use App\Repository\ScheduledWorkoutRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Rattache une activité importée à la séance datée dont elle est le réalisé,
 * **seulement quand il n'y a aucun doute**.
 *
 * Une candidate, c'est une séance du même propriétaire, le même jour local,
 * prévue ou faite, sans activité déjà rattachée, dont le prescrit contient
 * l'activité (une sortie vélo ne rattache pas une séance course). **Une seule
 * candidate : on rattache. Plusieurs : on ne devine pas**, l'activité reste « à
 * rattacher » et l'utilisateur choisit depuis la page de la séance. Un
 * rattachement faux est pire qu'un rattachement manquant : il déplace un chiffre
 * dans les statistiques sans que rien ne le signale.
 *
 * **Aucune candidate** : l'activité reste à rattacher, sauf si l'appelant demande
 * une **séance libre**. Seule la reprise d'historique le fait
 * (`app:intervals:import`) : le cardio se planifie dans Kadens, donc au
 * quotidien une sortie sans séance prévue signale un oubli de planification,
 * pas une séance à inventer. Pour un passé antérieur à Kadens, en revanche, il
 * n'y a rien à planifier après coup. Même modèle que `TrainingHistoryImporter` :
 * `workout = null`, titre en snapshot, statut « Faite », uuid déterministe.
 *
 * Rattacher passe une séance **prévue** en **faite** : une activité enregistrée
 * est la preuve que la séance a eu lieu. L'inverse n'est pas vrai, détacher ne
 * rétablit rien — sauf pour une séance libre créée par l'import, qui n'existait
 * que pour porter son activité (`isDisposableFreeSession`).
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
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function autoAttach(ImportedActivity $activity, bool $freeSessionWhenUnplanned = false): ActivityMatch
    {
        if (null === $activity->getActivity() || null !== $activity->getScheduledWorkout()) {
            return ActivityMatch::NOT_MATCHABLE;
        }

        $candidates = $this->candidates($activity);

        if (1 === \count($candidates)) {
            $this->attach($activity, $candidates[0]);
            $this->claimed[(int) $candidates[0]->getId()] = true;

            return ActivityMatch::ATTACHED;
        }

        if ([] !== $candidates) {
            return ActivityMatch::AMBIGUOUS;
        }

        if (!$freeSessionWhenUnplanned) {
            return ActivityMatch::NO_CANDIDATE;
        }

        $activity->setScheduledWorkout($this->freeSessionFor($activity));

        return ActivityMatch::FREE_SESSION;
    }

    /** Le rattachement lui-même, commun à l'automatique et au geste manuel. */
    public function attach(ImportedActivity $activity, ScheduledWorkout $scheduled): void
    {
        $activity->setScheduledWorkout($scheduled);

        if (ScheduledStatus::PLANNED === $scheduled->getStatus()) {
            $scheduled->setStatus(ScheduledStatus::DONE);
        }
    }

    /**
     * L'uuid de la séance libre d'une activité. Dérivé par le même namespace que
     * les reprises de salle, avec une clé préfixée par la source (règle de
     * `TrainingHistoryImporter::uuidFor`) : relancer l'import retombe sur la même
     * séance au lieu d'en poser une seconde.
     */
    public static function freeSessionUuid(ImportedActivity $activity): Uuid
    {
        return TrainingHistoryImporter::uuidFor($activity->getSource()->value.'|'.$activity->getExternalId());
    }

    /**
     * Une séance libre **créée par l'import pour cette activité**, et qui ne
     * porte rien d'autre : ni programme, ni réalisé de salle, ni autre activité.
     * La détacher la laisserait vide au calendrier, cochée « Faite » sur rien.
     */
    public function isDisposableFreeSession(ScheduledWorkout $scheduled, ImportedActivity $activity): bool
    {
        if (null !== $scheduled->getWorkout() || $scheduled->hasLog() || !self::freeSessionUuid($activity)->equals($scheduled->getUuid())) {
            return false;
        }

        foreach ($this->activities->findForScheduledWorkout($scheduled) as $other) {
            if ($other !== $activity && $other->getScheduledWorkout() === $scheduled) {
                return false;
            }
        }

        return true;
    }

    /** À appeler entre deux lots indépendants : le registre ne vaut que pour un import. */
    public function reset(): void
    {
        $this->claimed = [];
    }

    /**
     * @return list<ScheduledWorkout>
     */
    private function candidates(ImportedActivity $activity): array
    {
        $type = $activity->getActivity();
        $sameDay = $this->scheduledWorkouts->findPlannedOrDoneWithContentForOwnerOn($activity->getOwner(), $activity->getLocalDate());

        $matching = array_values(array_filter(
            $sameDay,
            fn (ScheduledWorkout $s): bool => null !== $s->getWorkout()
                && !isset($this->claimed[(int) $s->getId()])
                && \in_array($type, $this->metrics->distinctActivities($s->getWorkout()), true),
        ));

        $taken = $this->activities->scheduledIdsWithActivity(array_map(static fn (ScheduledWorkout $s): int => (int) $s->getId(), $matching));

        return array_values(array_filter($matching, static fn (ScheduledWorkout $s): bool => !\in_array((int) $s->getId(), $taken, true)));
    }

    /**
     * La séance libre qui porte une activité sans séance prévue. Si elle existe
     * déjà (activités purgées puis réimportées : la séance, elle, était restée),
     * on la reprend, sinon l'uuid déterministe violerait l'unicité.
     */
    private function freeSessionFor(ImportedActivity $activity): ScheduledWorkout
    {
        $uuid = self::freeSessionUuid($activity);
        $existing = $this->scheduledWorkouts->findByUuid($uuid);

        if (null !== $existing) {
            return $existing;
        }

        $startedAt = $activity->getStartedAt();
        $seconds = $activity->getElapsedSeconds() ?? $activity->getMovingSeconds();

        $session = (new ScheduledWorkout($uuid))
            ->setOwner($activity->getOwner())
            ->setTitle($activity->getName() ?? $activity->getActivity()?->getLabel() ?? $activity->getSportType())
            ->setScheduledDate($activity->getLocalDate())
            ->setStatus(ScheduledStatus::DONE)
            ->setStartedAt($startedAt)
            ->setEndedAt(null === $seconds ? null : $startedAt->modify(\sprintf('+%d seconds', $seconds)));

        $this->entityManager->persist($session);

        return $session;
    }
}
