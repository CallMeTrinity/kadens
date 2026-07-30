<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * Retrouve un jeton depuis le secret présenté par le client. La recherche se
     * fait sur l'empreinte, jamais sur le secret : c'est la seule façon de
     * chercher quelque chose que la base ne stocke pas.
     *
     * Ne filtre pas l'expiration — c'est à l'appelant de trancher, pour qu'un
     * jeton périmé se distingue d'un jeton inconnu côté journalisation.
     */
    public function findOneByPlainToken(string $plainToken): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => ApiToken::hash($plainToken)]);
    }

    /**
     * Les appareils d'un utilisateur, le plus récemment utilisé en tête (un
     * jeton jamais utilisé passe après). Sert la liste de KL-12.
     *
     * @return list<ApiToken>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('t.lastUsedAt', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
