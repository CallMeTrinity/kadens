<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workout>
 */
class WorkoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workout::class);
    }

    /**
     * Séances de bibliothèque d'un utilisateur : ses séances réutilisables, hors
     * copies locales aux plans (planLocal = true, portées par une case de trame).
     * Sert à l'index, aux sélecteurs de séance et au compositeur de trame.
     *
     * @param array<string, string> $orderBy
     *
     * @return list<Workout>
     */
    public function findLibraryForOwner(User $owner, array $orderBy = ['title' => 'ASC']): array
    {
        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.owner = :owner')
            ->andWhere('w.planLocal = false')
            ->setParameter('owner', $owner);

        foreach ($orderBy as $field => $direction) {
            $qb->addOrderBy('w.'.$field, $direction);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Comme findLibraryForOwner mais en chargeant le contenu (blocs -> exercices
     * prescrits -> exercice) en une requête. Sert la palette de l'éditeur de
     * trame, qui calcule pour chaque carte des repères dérivés (activités
     * distinctes, nombre d'exos) via WorkoutMetrics : sans ce fetch-join, ce
     * serait un N+1 sur toute la bibliothèque.
     *
     * @return list<Workout>
     */
    public function findLibraryForOwnerWithContent(User $owner): array
    {
        return $this->findLibraryForOwnersWithContent([$owner]);
    }

    /**
     * Variante multi-propriétaires : l'index des séances d'un coach liste aussi
     * celles de ses athlètes suivis (cf. CoachedLibrary). Réservée aux **listes de
     * consultation** — les sélecteurs qui posent une séance sur son propre
     * calendrier ou dans sa propre trame doivent rester sur la version à un seul
     * propriétaire, sinon on proposerait la séance d'un athlète à la pose.
     *
     * @param list<User> $owners
     *
     * @return list<Workout>
     */
    public function findLibraryForOwnersWithContent(array $owners): array
    {
        if ([] === $owners) {
            return [];
        }

        return $this->createQueryBuilder('w')
            ->addSelect('b', 'pe', 'ex')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'ex')
            ->andWhere('w.owner IN (:owners)')
            ->andWhere('w.planLocal = false')
            ->setParameter('owners', $owners)
            ->addOrderBy('w.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Workout[] Returns an array of Workout objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('w.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Workout
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
