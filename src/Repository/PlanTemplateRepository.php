<?php

namespace App\Repository;

use App\Entity\PlanTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanTemplate>
 */
class PlanTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanTemplate::class);
    }

    /**
     * Charge un plan avec tout son contenu (cases -> séance -> blocs -> exercices
     * prescrits -> exercice) en une requête. Sert la vue de progression prévue,
     * qui parcourt chaque case du plan : sans ce fetch-join, ce serait un N+1 sur
     * toutes les semaines. L'entité renvoyée est la même instance managée (identity
     * map) avec ses collections déjà hydratées.
     */
    public function findWithContent(int $id): ?PlanTemplate
    {
        return $this->createQueryBuilder('t')
            ->addSelect('i', 'w', 'b', 'pe', 'ex')
            ->leftJoin('t.planItems', 'i')
            ->leftJoin('i.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'ex')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return PlanTemplate[] Returns an array of PlanTemplate objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?PlanTemplate
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
