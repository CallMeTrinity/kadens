<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DeletedEntity;
use App\Entity\Exercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\DeletedEntityType;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Écrit une pierre tombale (`DeletedEntity`) chaque fois qu'un `Exercise` ou une
 * `ScheduledWorkout` est supprimé, pour que le delta de `GET /api/bootstrap`
 * puisse dire au téléphone ce qu'il doit oublier (KL-14).
 *
 * **Pourquoi un écouteur et non un appel explicite au moment de supprimer.** Il y
 * a une douzaine de points de suppression dans l'app (bibliothèque d'exercices,
 * retrait d'une case de plan, retrait au calendrier, suppression d'un plan
 * instancié…), et il s'en ajoutera. Un oubli à un seul de ces points ne casse
 * rien de visible : il laisse juste un fantôme dans la base locale d'un
 * téléphone, des semaines plus tard. Une panne sans symptôme se prévient
 * structurellement, elle ne se rattrape pas en relecture.
 *
 * **Deux temps, et ils sont nécessaires.** `onFlush` est le seul moment où l'on
 * voit encore les entités à supprimer *avec* leur identifiant et leur
 * propriétaire ; `postFlush` est le seul où l'on peut persister quelque chose de
 * nouveau sans se battre avec l'unité de travail en cours. La liste vidée avant
 * le second `flush()` suffit à borner la récursion : l'appel imbriqué repasse par
 * `onFlush` (aucune suppression programmée) puis par `postFlush` (rien en
 * attente) et s'arrête là.
 *
 * **Une limite assumée** : un `ON DELETE CASCADE` exécuté par la base ne
 * déclenche aucun événement Doctrine. Supprimer un compte emporte donc ses
 * séances datées sans laisser de pierre tombale — c'est sans conséquence, il n'y
 * a plus de jeton pour venir les réclamer.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TombstoneListener
{
    /** @var list<DeletedEntity> */
    private array $pending = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $tombstone = $this->tombstoneFor($entity, $unitOfWork);

            if (null !== $tombstone) {
                $this->pending[] = $tombstone;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        // Vidée AVANT le flush imbriqué : c'est ce qui borne la récursion.
        $tombstones = $this->pending;
        $this->pending = [];

        $em = $args->getObjectManager();
        foreach ($tombstones as $tombstone) {
            $em->persist($tombstone);
        }

        $em->flush();

        // **Puis on la détache, et ce n'est pas de l'hygiène.** Une pierre
        // tombale s'écrit une fois et n'est jamais relue dans la même requête ;
        // la laisser gérée la ferait visiter par tous les `flush()` suivants,
        // avec son `owner` — dont la suppression suit souvent de près celle de
        // son contenu. Doctrine remet une entité supprimée à l'état « neuf », et
        // le flush d'après échouerait sur « A new entity was found through the
        // relationship DeletedEntity#owner », loin d'ici et sans rapport visible.
        foreach ($tombstones as $tombstone) {
            $em->detach($tombstone);
        }
    }

    private function tombstoneFor(object $entity, UnitOfWork $unitOfWork): ?DeletedEntity
    {
        [$type, $key, $owner] = match (true) {
            $entity instanceof Exercise => [
                DeletedEntityType::EXERCISE,
                (string) $entity->getId(),
                $entity->getOwner(),
            ],
            $entity instanceof ScheduledWorkout => [
                DeletedEntityType::SCHEDULED_WORKOUT,
                (string) $entity->getUuid(),
                $entity->getOwner(),
            ],
            default => [null, '', null],
        };

        if (null === $type || '' === $key) {
            return null;
        }

        // Le propriétaire part dans la même transaction (suppression de compte) :
        // la pierre tombale n'aurait personne à prévenir, et sa clé étrangère
        // pointerait dans le vide.
        if ($owner instanceof User && $unitOfWork->isScheduledForDelete($owner)) {
            return null;
        }

        return new DeletedEntity($type, $key, $owner);
    }
}
