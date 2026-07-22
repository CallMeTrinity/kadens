<?php

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transforme un PlanTemplate abstrait (trame semaines × jours, sans dates) en N
 * ScheduledWorkout datés posés sur le calendrier. Le template reste intact :
 * c'est une projection, pas une conversion.
 *
 * Mapping semaine/jour -> date réelle : la trame numérote les jours en ISO
 * (1=lundi..7=dimanche, cf. PlanItem). On ancre donc la semaine 1 sur le **lundi
 * de la semaine ISO contenant la date de départ**. Chaque case tombe alors sur
 * son vrai jour de semaine (un item « mercredi » atterrit un mercredi), quelle
 * que soit le jour choisi comme point de départ.
 *
 * Chaque ScheduledWorkout référence le Workout vivant (décision figée, cf.
 * ROADMAP §2.3) et garde une trace du template source. L'instanciation n'est pas
 * idempotente : la relancer crée un second jeu de séances (le déclenchement est
 * une action explicite de l'utilisateur).
 */
final class PlanInstantiator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<ScheduledWorkout> les instances créées, déjà persistées
     */
    public function instantiate(PlanTemplate $template, User $owner, \DateTimeImmutable $startDate): array
    {
        // Ancre = lundi de la semaine ISO de la date de départ, à minuit.
        $isoDayOfWeek = (int) $startDate->format('N');
        $anchorMonday = $startDate
            ->setTime(0, 0)
            ->modify(sprintf('-%d days', $isoDayOfWeek - 1));

        $created = [];
        foreach ($template->getPlanItems() as $item) {
            $offsetDays = ($item->getWeekNumber() - 1) * 7 + ($item->getDayOfWeek() - 1);
            $date = $anchorMonday->modify(sprintf('+%d days', $offsetDays));

            $scheduled = (new ScheduledWorkout())
                ->setOwner($owner)
                ->setWorkout($item->getWorkout())
                ->setSourcePlanTemplate($template)
                ->setScheduledDate($date);

            $this->entityManager->persist($scheduled);
            $created[] = $scheduled;
        }

        $this->entityManager->flush();

        return $created;
    }
}
