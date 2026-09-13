<?php

namespace App\Repository;

use App\Entity\IntervalsConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IntervalsConnection>
 */
class IntervalsConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntervalsConnection::class);
    }

    public function findForOwner(User $owner): ?IntervalsConnection
    {
        return $this->findOneBy(['owner' => $owner]);
    }
}
