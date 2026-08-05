<?php

namespace App\Repository;

use App\Entity\LoggedSet;
use App\Entity\User;
use App\Enum\SetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LoggedSet>
 *
 * @phpstan-type PerfRow array{exerciseId: int, scheduledWorkoutId: int, date: \DateTimeImmutable, setType: SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null, rpe: int|null}
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

    /**
     * Séries de TRAVAIL de la séance la plus récente où chaque exercice demandé
     * apparaît. UNE requête quel que soit le nombre d'exercices : c'est ce qui
     * rend le bootstrap mobile tenable sur toute une bibliothèque (KL-04).
     *
     * La sélection de « la plus récente » se fait par sous-requête corrélée sur
     * la date planifiée plutôt qu'en remontant tout l'historique pour le trier
     * en PHP : l'historique d'un exercice grossit sans limite, la dernière
     * séance non.
     *
     * @param list<int> $exerciseIds
     *
     * @return list<PerfRow> triées par exercice, puis séance la plus récente
     *                       d'abord, puis ordre d'exécution
     */
    public function findLastWorkingSetsForExercises(User $owner, array $exerciseIds): array
    {
        if ([] === $exerciseIds) {
            return [];
        }

        $qb = $this->workingSetRows($owner, $exerciseIds);

        // Corrélée sur `le.exercise` : la borne est propre à chaque exercice.
        // Une même date peut porter deux séances (matin/soir) : l'appelant
        // départage sur l'identifiant, l'ordre ci-dessous le lui garantit.
        $qb->andWhere(sprintf(
            's.scheduledDate = (SELECT MAX(s2.scheduledDate) %s)',
            $this->correlatedFrom(),
        ));

        return $this->hydrateRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Séries de TRAVAIL portant la charge maximale jamais soulevée sur chaque
     * exercice demandé — le record. Une requête, même logique que ci-dessus.
     *
     * L'échauffement est exclu par construction (workingSetRows) : c'est la
     * règle qu'un mauvais filtre casserait en premier, un échauffement lourd
     * deviendrait un record. Les séries sans charge (poids du corps, série en
     * durée) sont écartées : il n'y a pas de record sans kilos.
     *
     * Plusieurs séries peuvent porter la même charge maximale ; toutes sont
     * renvoyées, c'est à l'appelant de les départager.
     *
     * @param list<int> $exerciseIds
     *
     * @return list<PerfRow>
     */
    public function findBestWorkingSetsForExercises(User $owner, array $exerciseIds): array
    {
        if ([] === $exerciseIds) {
            return [];
        }

        $qb = $this->workingSetRows($owner, $exerciseIds);

        $qb->andWhere('ls.weightKg IS NOT NULL')
            ->andWhere(sprintf(
                'ls.weightKg = (SELECT MAX(ls2.weightKg) %s)',
                $this->correlatedFrom(),
            ));

        return $this->hydrateRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Séries de TRAVAIL des `limit` dernières séances où un exercice apparaît —
     * la trajectoire que le téléphone affiche sur la fiche d'un exercice
     * (KL-17). Même périmètre que les deux lectures ci-dessus, à dessein : trois
     * chiffres lus sur trois définitions différentes de « ce qui compte » ne se
     * comparent pas.
     *
     * **Deux requêtes, et bornées toutes les deux.** L'historique d'un exercice
     * grossit sans limite : ramener toutes ses séries pour n'en garder que dix
     * séances marcherait la première année. On borne donc d'abord les séances
     * (`setMaxResults` sur des lignes distinctes), puis on lit les séries de
     * celles-là seulement.
     *
     * @return list<PerfRow> séance la plus récente d'abord, puis ordre
     *                       d'exécution
     */
    public function findRecentWorkingSetsForExercise(User $owner, int $exerciseId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        // `DISTINCT` parce qu'une séance porte plusieurs séries : ce qu'on
        // compte ici, ce sont les séances, pas les lignes.
        $sessions = $this->workingSetScope($owner, [$exerciseId])
            ->select('DISTINCT s.id AS id', 's.scheduledDate AS date')
            ->orderBy('s.scheduledDate', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        if ([] === $sessions) {
            return [];
        }

        $qb = $this->workingSetRows($owner, [$exerciseId])
            ->andWhere('s.id IN (:sessions)')
            ->setParameter('sessions', array_map(static fn (array $row): int => (int) $row['id'], $sessions));

        return $this->hydrateRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Socle commun des lectures de performance : les séries de travail d'un
     * utilisateur sur un jeu d'exercices, en projection scalaire (aucune entité
     * hydratée).
     *
     * Le statut de la séance n'est PAS filtré : le réalisé est un fait dès
     * qu'il est écrit, une séance encore PLANNED en cours de synchro compte
     * déjà. Un exercice sauté, lui, est écarté même s'il porte des séries
     * abandonnées — même règle que LogMetrics.
     *
     * @param list<int> $exerciseIds
     */
    private function workingSetRows(User $owner, array $exerciseIds): \Doctrine\ORM\QueryBuilder
    {
        return $this->workingSetScope($owner, $exerciseIds)
            ->select(
                'IDENTITY(le.exercise) AS exerciseId',
                's.id AS scheduledWorkoutId',
                's.scheduledDate AS date',
                'ls.setType AS setType',
                'ls.reps AS reps',
                'ls.weightKg AS weightKg',
                'ls.durationSeconds AS durationSeconds',
                'ls.rpe AS rpe',
            )
            ->orderBy('exerciseId', 'ASC')
            ->addOrderBy('s.scheduledDate', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->addOrderBy('le.position', 'ASC')
            ->addOrderBy('ls.position', 'ASC');
    }

    /**
     * Le périmètre, sans projection ni tri : « les séries de travail de cet
     * utilisateur sur ces exercices ». C'est la définition que les trois
     * lectures partagent — dernière performance, record, séances récentes — et
     * la seule chose qui garantit qu'elles parlent du même réalisé.
     *
     * @param list<int> $exerciseIds
     */
    private function workingSetScope(User $owner, array $exerciseIds): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('ls')
            ->join('ls.loggedExercise', 'le')
            ->join('le.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('le.exercise IN (:exercises)')
            ->andWhere('le.skipped = false')
            ->andWhere('ls.setType != :warmup')
            ->setParameter('owner', $owner)
            ->setParameter('exercises', $exerciseIds)
            ->setParameter('warmup', SetType::WARMUP->value);
    }

    /**
     * Le FROM/WHERE des sous-requêtes corrélées, identique aux deux : mêmes
     * filtres que la requête externe, mais restreints à l'exercice de la ligne
     * courante. Écrit une seule fois pour que les deux bornes (dernière séance,
     * record) ne puissent pas diverger de leur périmètre.
     */
    private function correlatedFrom(): string
    {
        return 'FROM '.LoggedSet::class.' ls2'
            .' JOIN ls2.loggedExercise le2'
            .' JOIN le2.scheduledWorkout s2'
            .' WHERE le2.exercise = le.exercise'
            .' AND s2.owner = :owner'
            .' AND le2.skipped = false'
            .' AND ls2.setType != :warmup';
    }

    /**
     * Normalise les lignes scalaires : selon la version de Doctrine, un champ
     * `enumType` et une colonne date reviennent déjà convertis ou sous leur
     * forme brute. On ne laisse pas ce doute remonter jusqu'au service.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PerfRow>
     */
    private function hydrateRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $date = $row['date'];
            $type = $row['setType'];

            return [
                'exerciseId' => (int) $row['exerciseId'],
                'scheduledWorkoutId' => (int) $row['scheduledWorkoutId'],
                'date' => $date instanceof \DateTimeImmutable
                    ? $date
                    : new \DateTimeImmutable((string) $date),
                'setType' => $type instanceof SetType ? $type : SetType::from((string) $type),
                'reps' => null !== $row['reps'] ? (int) $row['reps'] : null,
                'weightKg' => null !== $row['weightKg'] ? (float) $row['weightKg'] : null,
                'durationSeconds' => null !== $row['durationSeconds'] ? (int) $row['durationSeconds'] : null,
                'rpe' => null !== $row['rpe'] ? (int) $row['rpe'] : null,
            ];
        }, $rows);
    }
}
