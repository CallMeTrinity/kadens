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
     * Le volume de SALLE réalisé, agrégé par jour de séance : c'est la matière
     * de toutes les statistiques de force de `/profile/stats` — tonnage,
     * séries, durée sous tension, RPE moyen, et la rampe semaine par semaine
     * qu'on en déduit en PHP.
     *
     * **Aucune entité hydratée, une requête, quelle que soit la fenêtre.** C'est
     * ce qui rend « depuis le début » aussi tenable que « quatre semaines » : le
     * résultat grossit en nombre de jours d'entraînement, pas en nombre de
     * séries. L'ancienne lecture, qui remontait tout l'historique de séances
     * fetch-joint pour le sommer en PHP, ne tenait que parce qu'elle n'était
     * appelée qu'une fois.
     *
     * Le RPE revient en somme + effectif plutôt qu'en moyenne : des moyennes de
     * moyennes ne se recomposent pas, et l'appelant a besoin de la moyenne sur
     * la fenêtre entière comme sur chaque semaine.
     *
     * Périmètre : celui de `workingSetScope()` — échauffement exclu, exercice
     * sauté exclu, série non chiffrée exclue (cf. `measured()`), statut de la
     * séance non filtré (le réalisé est un fait dès qu'il est écrit).
     *
     * @return list<array{date: \DateTimeImmutable, sessions: int, workingSets: int, tonnageKg: float, seconds: int, rpeSum: int, rpeCount: int}>
     */
    public function gymTotalsByDateForOwner(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->workingSetWindow($owner, $start, $end)
            ->select(
                's.scheduledDate AS date',
                'COUNT(DISTINCT s.id) AS sessions',
                'COUNT(ls.id) AS workingSets',
                'SUM(CASE WHEN ls.reps IS NOT NULL AND ls.weightKg IS NOT NULL THEN ls.reps * ls.weightKg ELSE 0 END) AS tonnage',
                'SUM(COALESCE(ls.durationSeconds, 0)) AS seconds',
                'SUM(COALESCE(ls.rpe, 0)) AS rpeSum',
                'SUM(CASE WHEN ls.rpe IS NOT NULL THEN 1 ELSE 0 END) AS rpeCount',
            )
            ->groupBy('s.scheduledDate')
            ->orderBy('s.scheduledDate', 'ASC');

        return array_map(static fn (array $row): array => [
            'date' => $row['date'] instanceof \DateTimeImmutable
                ? $row['date']
                : new \DateTimeImmutable((string) $row['date']),
            'sessions' => (int) $row['sessions'],
            'workingSets' => (int) $row['workingSets'],
            'tonnageKg' => (float) $row['tonnage'],
            'seconds' => (int) $row['seconds'],
            'rpeSum' => (int) $row['rpeSum'],
            'rpeCount' => (int) $row['rpeCount'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * Le même volume, agrégé par exercice : d'où sortent la ventilation par
     * groupe musculaire (l'appelant croise `exerciseId` avec les `targetAreas`
     * de la bibliothèque) et le classement des charges de la fenêtre.
     *
     * Le regroupement porte sur l'identifiant **et** le nom figé : un
     * `LoggedExercise` dont la définition a été supprimée (SET NULL) n'a plus
     * que son nom, et l'écarter ferait disparaître du volume réellement
     * soulevé. L'appelant replie les lignes de même exercice.
     *
     * @return list<array{exerciseId: int|null, name: string, workingSets: int, tonnageKg: float, topWeightKg: float|null, sessions: int}>
     */
    public function gymTotalsByExerciseForOwner(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->workingSetWindow($owner, $start, $end)
            ->select(
                'IDENTITY(le.exercise) AS exerciseId',
                'le.exerciseName AS name',
                'COUNT(ls.id) AS workingSets',
                'SUM(CASE WHEN ls.reps IS NOT NULL AND ls.weightKg IS NOT NULL THEN ls.reps * ls.weightKg ELSE 0 END) AS tonnage',
                'MAX(ls.weightKg) AS topWeight',
                'COUNT(DISTINCT s.id) AS sessions',
            )
            ->groupBy('exerciseId')
            ->addGroupBy('le.exerciseName')
            ->orderBy('workingSets', 'DESC');

        return array_map(static fn (array $row): array => [
            'exerciseId' => null !== $row['exerciseId'] ? (int) $row['exerciseId'] : null,
            'name' => (string) $row['name'],
            'workingSets' => (int) $row['workingSets'],
            'tonnageKg' => (float) $row['tonnage'],
            'topWeightKg' => null !== $row['topWeight'] ? (float) $row['topWeight'] : null,
            'sessions' => (int) $row['sessions'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * La charge maximale soulevée sur chaque exercice **avant** une date : la
     * référence contre laquelle se juge un record de la fenêtre.
     *
     * Un record est un fait relatif à un passé. Sans borne haute il n'y a pas
     * de passé à comparer, et la méthode n'a rien à dire : l'appelant ne
     * l'appelle donc que sur une fenêtre bornée (« depuis le début » n'a pas de
     * *nouveaux* records, il n'a que des records).
     *
     * @return array<int, float> charge max, indexée par identifiant d'exercice
     */
    public function maxWeightByExerciseBefore(User $owner, \DateTimeImmutable $before): array
    {
        $rows = $this->workingSetWindow($owner, null, null)
            ->select('IDENTITY(le.exercise) AS exerciseId', 'MAX(ls.weightKg) AS topWeight')
            ->andWhere('le.exercise IS NOT NULL')
            ->andWhere('ls.weightKg IS NOT NULL')
            ->andWhere('s.scheduledDate < :before')
            ->setParameter('before', $before, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->groupBy('exerciseId')
            ->getQuery()
            ->getArrayResult();

        $max = [];
        foreach ($rows as $row) {
            $max[(int) $row['exerciseId']] = (float) $row['topWeight'];
        }

        return $max;
    }

    /**
     * Le périmètre des agrégats : « les séries de travail de cet utilisateur »,
     * éventuellement bornées dans le temps. Mêmes filtres que
     * `workingSetScope()` au mot près — échauffement, exercice sauté et série
     * non chiffrée exclus — mais sans restriction d'exercice : ici on lit tout
     * ce qui a été fait.
     *
     * Les bornes sont facultatives et indépendantes, comme partout dans les
     * statistiques : « depuis le début » n'a pas de borne basse.
     */
    private function workingSetWindow(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('ls')
            ->join('ls.loggedExercise', 'le')
            ->join('le.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('le.skipped = false')
            ->andWhere('ls.setType != :warmup')
            ->andWhere(self::measured('ls'))
            ->setParameter('owner', $owner)
            ->setParameter('warmup', SetType::WARMUP->value);

        if (null !== $start) {
            $qb->andWhere('s.scheduledDate >= :windowStart')
                ->setParameter('windowStart', $start, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }

        if (null !== $end) {
            $qb->andWhere('s.scheduledDate <= :windowEnd')
                ->setParameter('windowEnd', $end, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }

        return $qb;
    }

    /**
     * Socle commun des lectures de performance : les séries de travail d'un
     * utilisateur sur un jeu d'exercices, en projection scalaire (aucune entité
     * hydratée).
     *
     * Le statut de la séance n'est PAS filtré : le réalisé est un fait dès
     * qu'il est écrit, une séance encore PLANNED en cours de synchro compte
     * déjà. Un exercice sauté, lui, est écarté même s'il porte des séries
     * abandonnées — même règle que LogMetrics. Une série cochée sans aucune
     * valeur est écartée aussi (cf. `measured()`) : elle n'a rien à dire d'une
     * performance.
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
            ->andWhere(self::measured('ls'))
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
            .' AND ls2.setType != :warmup'
            .' AND '.self::measured('ls2');
    }

    /**
     * Le pendant SQL de `LoggedSet::countsAsWorking()` : une série n'entre dans
     * le volume que si elle est CHIFFRÉE — au moins une répétition, ou au moins
     * une seconde. Une série cochée sans valeur (« ? » à l'écran) a bien eu
     * lieu, mais elle ne mesure rien : la compter gonflerait le décompte de
     * séries, la ventilation par région et la moyenne par séance avec du vide,
     * et ferait remonter une séance sans contenu comme « dernière performance ».
     *
     * `COALESCE` plutôt que `reps > 0` seul : en SQL `NULL > 0` ne vaut pas
     * faux, il vaut NULL — et une série en durée, qui n'a pas de répétitions,
     * doit rester dans le périmètre.
     *
     * Le prédicat est paramétré par l'alias parce qu'il sert aussi dans les
     * sous-requêtes corrélées (`ls2`), dont le périmètre doit être identique à
     * celui de la requête externe au mot près.
     *
     * Les deux définitions, PHP et SQL, doivent bouger ensemble.
     */
    private static function measured(string $alias): string
    {
        return sprintf('(COALESCE(%1$s.reps, 0) > 0 OR COALESCE(%1$s.durationSeconds, 0) > 0)', $alias);
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
