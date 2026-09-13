<?php

namespace App\Controller;

use App\Entity\ImportedActivity;
use App\Entity\ScheduledWorkout;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Service\ActivityMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rattacher ou détacher à la main une activité importée, depuis la page d'une
 * séance datée. C'est le recours de `ActivityMatcher`, qui ne rattache que
 * lorsqu'il n'y a qu'une candidate.
 *
 * **L'attribut est LOG, pas EDIT.** Désigner la sortie qui constitue le réalisé
 * d'une séance, c'est consigner ce qui a été fait : réservé au propriétaire,
 * comme la suppression du réalisé de muscu. Le coach lit l'activité, il ne
 * décide pas laquelle son athlète a courue.
 *
 * Indépendant de la source : ces routes ne savent pas d'où vient l'activité.
 */
#[Route('/schedule/{id}/activity', requirements: ['id' => '\d+'])]
final class ImportedActivityController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Une activité d'un autre compte rend **404** : même règle que les appareils,
     * un refus ne confirme pas qu'un identifiant existe.
     */
    #[Route('/{activityId}/attach', name: 'app_imported_activity_attach', methods: ['POST'], requirements: ['activityId' => '\d+'])]
    public function attach(
        Request $request,
        ScheduledWorkout $scheduled,
        #[MapEntity(id: 'activityId')] ImportedActivity $activity,
        ActivityMatcher $matcher,
    ): Response {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::LOG, $scheduled);

        if ($activity->getOwner() !== $scheduled->getOwner()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('activity_attach'.$activity->getId(), $request->getPayload()->getString('_token'))) {
            $matcher->attach($activity, $scheduled);
            $this->entityManager->flush();

            $this->addFlash('success', 'Activité rattachée : elle devient le réalisé de cette séance.');
        }

        return $this->redirectToRoute('app_scheduled_workout_show', ['id' => $scheduled->getId()]);
    }

    /**
     * Détacher ne touche pas au statut : la séance a pu être faite sans que ce
     * soit cette activité-là (mauvais rattachement du même jour).
     */
    #[Route('/{activityId}/detach', name: 'app_imported_activity_detach', methods: ['POST'], requirements: ['activityId' => '\d+'])]
    public function detach(
        Request $request,
        ScheduledWorkout $scheduled,
        #[MapEntity(id: 'activityId')] ImportedActivity $activity,
    ): Response {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::LOG, $scheduled);

        if ($activity->getScheduledWorkout() !== $scheduled) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('activity_detach'.$activity->getId(), $request->getPayload()->getString('_token'))) {
            $activity->setScheduledWorkout(null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Activité détachée. Elle reste importée et peut être rattachée ailleurs.');
        }

        return $this->redirectToRoute('app_scheduled_workout_show', ['id' => $scheduled->getId()]);
    }
}
