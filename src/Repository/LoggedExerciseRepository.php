<?php

namespace App\Repository;

use App\Entity\LoggedExercise;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoggedExercise>
 */
class LoggedExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoggedExercise::class);
    }

    /**
     * L'usage RÉEL de chaque exercice par un utilisateur : combien de fois il l'a
     * fait, et quand pour la dernière fois. Alimente les trois tris de la
     * bibliothèque (KL-51).
     *
     * **Une seule requête d'agrégat**, fusionnée en PHP avec la liste déjà
     * chargée : `/exercise` charge toute la bibliothèque en une fois et filtre
     * côté client, un compte par carte y ferait un N+1 pour un affichage discret.
     *
     * **Scopée sur l'utilisateur.** Un exercice de la bibliothèque globale est
     * partagé : « le plus exécuté » veut dire « par moi », jamais « par tout le
     * monde ». Même piège que KL-50, même garde.
     *
     * Ce qui compte pour « fait » : une occurrence de l'exercice dans une séance
     * datée, non sautée — la même définition que le décompte d'exercices de
     * `LogMetrics`. Un exercice annoncé sauté n'a pas été fait, et une occurrence
     * dont l'exercice de bibliothèque a été supprimé (FK en SET NULL) n'a plus
     * personne à créditer.
     *
     * @return array<int, array{count: int, lastAt: \DateTimeImmutable|null}> indexé par identifiant d'exercice
     */
    public function usageForOwner(User $owner): array
    {
        $rows = $this->createQueryBuilder('le')
            ->select(
                'IDENTITY(le.exercise) AS exerciseId',
                'COUNT(le.id) AS cnt',
                'MAX(s.scheduledDate) AS lastAt',
            )
            ->join('le.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('le.exercise IS NOT NULL')
            ->andWhere('le.skipped = false')
            ->setParameter('owner', $owner)
            ->groupBy('exerciseId')
            ->getQuery()
            ->getArrayResult();

        $usage = [];
        foreach ($rows as $row) {
            $lastAt = $row['lastAt'];
            $usage[(int) $row['exerciseId']] = [
                'count' => (int) $row['cnt'],
                // Selon la version de Doctrine, un MAX() sur une colonne date
                // revient converti ou brut : on ne laisse pas ce doute sortir.
                'lastAt' => match (true) {
                    null === $lastAt => null,
                    $lastAt instanceof \DateTimeImmutable => $lastAt,
                    default => new \DateTimeImmutable((string) $lastAt),
                },
            ];
        }

        return $usage;
    }

    /**
     * Ce que chaque séance datée a porté d'exercices : combien réellement faits,
     * combien sautés. Complète l'agrégat de séries de `LoggedSetRepository` pour
     * écrire une ligne de journal (`TrainingLog`).
     *
     * Le saut est une **information**, pas du volume : il ne gonfle pas le compte
     * d'exercices et se dit à part, exactement comme dans `LogMetrics::summary()`.
     *
     * Une requête d'agrégat, aucune entité hydratée, quelle que soit la fenêtre.
     *
     * @return array<int, array{exercises: int, skipped: int}> indexé par identifiant de séance datée
     */
    public function countsByScheduledForOwner(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('le')
            ->select(
                's.id AS scheduledId',
                'SUM(CASE WHEN le.skipped = false THEN 1 ELSE 0 END) AS done',
                'SUM(CASE WHEN le.skipped = true THEN 1 ELSE 0 END) AS skipped',
            )
            ->join('le.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('s.id');

        if (null !== $start) {
            $qb->andWhere('s.scheduledDate >= :windowStart')
                ->setParameter('windowStart', $start, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }

        if (null !== $end) {
            $qb->andWhere('s.scheduledDate <= :windowEnd')
                ->setParameter('windowEnd', $end, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['scheduledId']] = [
                'exercises' => (int) $row['done'],
                'skipped' => (int) $row['skipped'],
            ];
        }

        return $counts;
    }
}
