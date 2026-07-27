<?php

namespace App\Repository;

use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoggedSet>
 */
class LoggedSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoggedSet::class);
    }

    /**
     * Toutes les séries réalisées d'une séance datée, indexées par exercice
     * prescrit puis par rang : `[prescribedId][setIndex] => LoggedSet`.
     *
     * Cette forme est celle que consomme la vue d'exécution, qui doit répondre
     * « cette ligne est-elle validée ? » pour chaque série affichée. Un accès
     * direct évite le N+1 qu'une recherche par ligne provoquerait.
     *
     * @return array<int, array<int, LoggedSet>>
     */
    public function indexedFor(ScheduledWorkout $scheduled): array
    {
        $logs = $this->createQueryBuilder('l')
            ->andWhere('l.scheduledWorkout = :scheduled')
            ->setParameter('scheduled', $scheduled)
            ->orderBy('l.setIndex', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($logs as $log) {
            $prescribedId = $log->getPrescribedExercise()?->getId();
            if (null !== $prescribedId) {
                $indexed[$prescribedId][$log->getSetIndex()] = $log;
            }
        }

        return $indexed;
    }
}
