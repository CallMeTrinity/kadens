<?php

namespace App\Service;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ScheduledStatus;
use App\Repository\ScheduledWorkoutRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pont trame <-> calendrier. Projette un PlanTemplate (semaines × jours, sans
 * dates) sur des ScheduledWorkout datés, et maintient cette projection vivante :
 * les cases ajoutées au plan APRÈS l'instanciation apparaissent au calendrier
 * (resync « add-only »), et comme chaque séance datée référence la copie locale
 * de la case (même entité), les modifications de contenu s'y reflètent d'office.
 *
 * Ancrage : semaine 1 = lundi ISO de la semaine contenant la date de départ. Un
 * item « mercredi » retombe donc toujours un mercredi. L'ancre est mémorisée sur
 * chaque séance datée (planAnchorDate) pour dater les cases ajoutées plus tard
 * sans redemander la date de départ.
 *
 * Contrat de préservation (décision « préserver le réalisé ») : le resync
 * n'ajoute que les cases manquantes et ne TOUCHE jamais une séance datée
 * existante (ni sa date, ni son statut). Le retrait d'une case et la préservation
 * du réalisé sont gérés à la pose/au retrait, pas ici.
 */
final class PlanScheduler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduledWorkoutRepository $scheduledRepository,
    ) {
    }

    /**
     * Première instanciation depuis une date de départ. Si le plan est déjà sur le
     * calendrier de l'utilisateur, ne recrée rien : resynchronise (idempotent).
     *
     * @return list<ScheduledWorkout> les séances datées créées
     */
    public function instantiate(PlanTemplate $template, User $owner, \DateTimeImmutable $startDate): array
    {
        if (\count($this->scheduledRepository->findBySourcePlanTemplateForOwner($template, $owner)) > 0) {
            return $this->resync($template, $owner);
        }

        $anchor = $this->anchorMonday($startDate);

        $created = [];
        foreach ($template->getPlanItems() as $item) {
            $created[] = $this->createScheduled($template, $owner, $item, $anchor);
        }

        $this->entityManager->flush();

        return $created;
    }

    /**
     * Resynchronise le calendrier avec la trame pour un plan déjà instancié :
     * ajoute une séance datée pour chaque case qui n'en a pas encore. No-op si le
     * plan n'a jamais été instancié (l'utilisateur n'a pas demandé à le poser).
     *
     * @return list<ScheduledWorkout> les séances datées ajoutées
     */
    public function resync(PlanTemplate $template, ?User $owner = null): array
    {
        $owner ??= $template->getOwner();
        if (null === $owner) {
            return [];
        }

        $existing = $this->scheduledRepository->findBySourcePlanTemplateForOwner($template, $owner);
        if (0 === \count($existing)) {
            return [];
        }

        $anchor = $this->resolveAnchor($existing);

        // Cases déjà matérialisées, indexées par id de PlanItem source.
        $scheduledItemIds = [];
        foreach ($existing as $scheduled) {
            $sourceItem = $scheduled->getSourcePlanItem();
            if (null !== $sourceItem) {
                $scheduledItemIds[$sourceItem->getId()] = true;
            }
        }

        $created = [];
        foreach ($template->getPlanItems() as $item) {
            if (!isset($scheduledItemIds[$item->getId()])) {
                $created[] = $this->createScheduled($template, $owner, $item, $anchor);
            }
        }

        if ([] !== $created) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * Vrai si ce plan est déjà posé sur le calendrier de l'utilisateur (au moins
     * une séance datée en provient). Utilisé pour ne resync qu'à bon escient.
     */
    public function isInstantiated(PlanTemplate $template, User $owner): bool
    {
        return \count($this->scheduledRepository->findBySourcePlanTemplateForOwner($template, $owner)) > 0;
    }

    /**
     * Réaligne les séances datées ENCORE PRÉVUES d'une case sur sa nouvelle
     * position (semaine/jour) dans la trame : déplacer une case dans l'éditeur de
     * plan déplace la séance « prévu » au calendrier. Les séances DONE/MISSED
     * gardent leur date (leur date matérialise le réalisé). No-op si la case n'a
     * pas de séance datée.
     *
     * À appeler APRÈS avoir mis à jour la position de la case (semaine/jour) :
     * l'ancre est indépendante de la position (planAnchorDate), donc l'ordre
     * n'affecte que la nouvelle date calculée.
     *
     * @return int nombre de séances prévues déplacées
     */
    public function rescheduleItem(PlanItem $item, User $owner): int
    {
        $scheduledList = $this->scheduledRepository->findBySourcePlanItem($item);
        if (0 === \count($scheduledList)) {
            return 0;
        }

        // Repli d'ancre pour les séances antérieures à planAnchorDate : reconstruit
        // depuis l'ensemble des séances du plan (une seule fois, à la demande).
        $planAnchor = null;

        $moved = 0;
        foreach ($scheduledList as $scheduled) {
            if (ScheduledStatus::PLANNED !== $scheduled->getStatus()) {
                continue;
            }

            $anchor = $scheduled->getPlanAnchorDate();
            if (null === $anchor) {
                $template = $scheduled->getSourcePlanTemplate();
                $planAnchor ??= null === $template
                    ? $this->anchorMonday(new \DateTimeImmutable('today'))
                    : $this->resolveAnchor($this->scheduledRepository->findBySourcePlanTemplateForOwner($template, $owner));
                $anchor = $planAnchor;
            }

            $scheduled->setScheduledDate($this->dateForItem($anchor, $item));
            ++$moved;
        }

        if ($moved > 0) {
            $this->entityManager->flush();
        }

        return $moved;
    }

    private function createScheduled(PlanTemplate $template, User $owner, PlanItem $item, \DateTimeImmutable $anchor): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            // La séance datée pointe la copie LOCALE de la case : toute édition de
            // cette copie (progression) se reflète d'office au calendrier.
            ->setWorkout($item->getWorkout())
            ->setSourcePlanTemplate($template)
            ->setSourcePlanItem($item)
            ->setPlanAnchorDate($anchor)
            ->setScheduledDate($this->dateForItem($anchor, $item));

        $this->entityManager->persist($scheduled);

        return $scheduled;
    }

    /**
     * Ancre = lundi de la semaine ISO de la date de départ, à minuit.
     */
    private function anchorMonday(\DateTimeImmutable $startDate): \DateTimeImmutable
    {
        $isoDayOfWeek = (int) $startDate->format('N');

        return $startDate->setTime(0, 0)->modify(sprintf('-%d days', $isoDayOfWeek - 1));
    }

    private function dateForItem(\DateTimeImmutable $anchor, PlanItem $item): \DateTimeImmutable
    {
        $offsetDays = ($item->getWeekNumber() - 1) * 7 + ($item->getDayOfWeek() - 1);

        return $anchor->modify(sprintf('+%d days', $offsetDays));
    }

    /**
     * Ancre de l'instance : lue sur une séance datée qui la porte. Repli pour les
     * séances antérieures à ce champ : on reconstruit l'ancre depuis une séance
     * dont on connaît la case (date - offset de la case).
     *
     * @param list<ScheduledWorkout> $existing
     */
    private function resolveAnchor(array $existing): \DateTimeImmutable
    {
        foreach ($existing as $scheduled) {
            if (null !== $scheduled->getPlanAnchorDate()) {
                return $scheduled->getPlanAnchorDate();
            }
        }

        foreach ($existing as $scheduled) {
            $item = $scheduled->getSourcePlanItem();
            if (null !== $item && null !== $scheduled->getScheduledDate()) {
                $offsetDays = ($item->getWeekNumber() - 1) * 7 + ($item->getDayOfWeek() - 1);

                return $scheduled->getScheduledDate()->modify(sprintf('-%d days', $offsetDays));
            }
        }

        // Dernier repli : lundi de la semaine de la plus ancienne séance datée.
        $earliest = null;
        foreach ($existing as $scheduled) {
            $date = $scheduled->getScheduledDate();
            if (null !== $date && (null === $earliest || $date < $earliest)) {
                $earliest = $date;
            }
        }

        return $this->anchorMonday($earliest ?? new \DateTimeImmutable('today'));
    }
}
