<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\GoalBlock;
use App\Service\GoalPhotoStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Efface du disque la photo d'un bloc supprimé — qu'on retire le bloc seul ou
 * l'objectif entier (cascade ORM, cf. `Goal::$blocks`).
 *
 * En deux temps, comme TombstoneListener : les fichiers sont **repérés** au
 * `onFlush` et **effacés** au `postFlush`, une fois la transaction validée. Un
 * flush qui échoue ne laisse donc jamais un bloc en base pointant vers une photo
 * déjà partie.
 *
 * Limite connue : une suppression faite par la base seule (clé étrangère en
 * CASCADE, quand un compte est supprimé) ne passe pas par l'ORM et laisse ses
 * fichiers. Ils sont inertes — plus aucun bloc ne les cite, donc plus aucune
 * route ne les sert.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class GoalPhotoCleanupListener
{
    /** @var list<string> */
    private array $pending = [];

    public function __construct(
        private readonly GoalPhotoStorage $storage,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof GoalBlock && null !== $entity->getPhotoFile()) {
                $this->pending[] = $entity->getPhotoFile();
            }
        }
    }

    public function postFlush(): void
    {
        $files = $this->pending;
        $this->pending = [];

        foreach ($files as $file) {
            $this->storage->delete($file);
        }
    }
}
