<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;
use App\Entity\ExerciseSetup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Les réglages de machine d'un pratiquant (#8 mobile).
 *
 * @extends ServiceEntityRepository<ExerciseSetup>
 */
class ExerciseSetupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseSetup::class);
    }

    public function findOneFor(User $owner, Exercise $exercise): ?ExerciseSetup
    {
        return $this->findOneBy(['owner' => $owner, 'exercise' => $exercise]);
    }

    /**
     * Les réglages de `$owner` sur les exercices donnés, en projection scalaire.
     *
     * Le filtre sur `$exerciseIds` n'est pas une optimisation : c'est la portée.
     * Un réglage noté sur l'exercice perso d'un coach reste en base quand la
     * relation se termine, mais l'exercice ne descend plus sur le téléphone — son
     * réglage ne doit pas descendre non plus, il pointerait dans le vide.
     *
     * @param list<int> $exerciseIds
     *
     * @return list<array{exerciseId: int, text: string, updatedAt: \DateTimeImmutable}>
     */
    public function listFor(User $owner, array $exerciseIds): array
    {
        if ([] === $exerciseIds) {
            return [];
        }

        /** @var list<array{exerciseId: int|string, text: string, updatedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.exercise) AS exerciseId', 's.text', 's.updatedAt')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.exercise IN (:ids)')
            ->setParameter('owner', $owner)
            ->setParameter('ids', $exerciseIds)
            ->orderBy('s.exercise', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return array_map(static fn (array $row): array => [
            'exerciseId' => (int) $row['exerciseId'],
            'text' => $row['text'],
            'updatedAt' => $row['updatedAt'],
        ], $rows);
    }
}
