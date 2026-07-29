<?php

namespace App\Repository;

use App\Entity\LoggedSet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     * Retrouve une série par son identifiant client. Base de l'idempotence de
     * l'écriture différée : une série rejouée se réécrit au lieu de se dupliquer.
     */
    public function findByUuid(Uuid $uuid): ?LoggedSet
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }
}
