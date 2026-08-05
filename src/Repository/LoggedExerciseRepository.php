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
}
