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
