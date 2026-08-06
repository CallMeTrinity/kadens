<?php

namespace App\Repository;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercise>
 */
class ExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /**
     * @return Exercise[]
     */
    public function findByActivity(ActivityType $activity): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.activity = :activity')
            ->setParameter('activity', $activity)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Bibliothèque visible par un utilisateur : ses exercices perso + la
     * bibliothèque globale de l'app (owner null).
     *
     * @return Exercise[]
     */
    public function findLibraryForUser(User $user): array
    {
        return $this->findLibraryForUsers([$user]);
    }

    /**
     * Variante multi-propriétaires : la globale + les exercices perso de PLUSIEURS
     * membres. Sert le compositeur, où l'on croise deux bibliothèques quand un
     * coach compose la séance d'un athlète — la sienne (ses variantes maison) et
     * celle de l'athlète. La séance appartenant à l'athlète, c'est le seul moyen
     * pour chacun d'utiliser les exercices de l'autre.
     *
     * @param list<User> $users
     *
     * @return Exercise[]
     */
    public function findLibraryForUsers(array $users): array
    {
        return $this->createLibraryQueryBuilder($users)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Même bibliothèque, bornée aux exercices modifiés depuis `$since` (null = le
     * jeu complet). C'est le delta de `GET /api/bootstrap` (KL-14).
     *
     * Le filtre porte sur **`COALESCE(updatedAt, createdAt)`** et non sur
     * `updatedAt` seul : celui-ci n'est écrit qu'au `preUpdate`, il reste donc
     * null tant qu'un exercice n'a jamais été retouché — c'est-à-dire pour la
     * quasi-totalité de la bibliothèque globale importée en console. Un filtre
     * naïf les ferait tous disparaître du delta, et un exercice créé après le
     * dernier bootstrap n'arriverait jamais sur le téléphone.
     *
     * @param list<User> $users
     *
     * @return Exercise[]
     */
    public function findLibraryForUsersChangedSince(array $users, ?\DateTimeImmutable $since): array
    {
        $qb = $this->createLibraryQueryBuilder($users);

        if (null !== $since) {
            $qb
                ->andWhere('COALESCE(e.updatedAt, e.createdAt) >= :since')
                ->setParameter('since', $since)
            ;
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les seuls identifiants de la bibliothèque visible, sans hydrater d'entité.
     * Sert l'historique du bootstrap (KL-14), qui porte sur la bibliothèque
     * entière même quand la réponse ne transporte qu'un delta.
     *
     * @param list<User> $users
     *
     * @return list<int>
     */
    public function libraryIdsForUsers(array $users): array
    {
        $rows = $this->createLibraryQueryBuilder($users)
            ->select('e.id')
            ->getQuery()
            ->getScalarResult()
        ;

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Les noms de la bibliothèque globale (owner null), indexés par clé
     * normalisée (minuscules, sans espaces de bord) → id.
     *
     * Sert la bascule perso → global (`app:exercise:globalize`) : avant de
     * publier l'exercice d'un admin, il faut savoir si la globale porte déjà ce
     * nom. La normalisation est plus large que le `findOneBy(['name' => ...])`
     * de l'import, qui compare au caractère près : un « Rowing barre » et un
     * « rowing barre » ne sont pas un doublon pour SQL mais en sont un dans une
     * liste que tout le monde lit.
     *
     * @return array<string, int>
     */
    public function globalNameIndex(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.id', 'e.name')
            ->andWhere('e.owner IS NULL')
            ->getQuery()
            ->getScalarResult()
        ;

        $index = [];
        foreach ($rows as $row) {
            $index[mb_strtolower(trim((string) $row['name']))] = (int) $row['id'];
        }

        return $index;
    }

    /**
     * QueryBuilder de la bibliothèque visible : la globale (owner null) plus les
     * exercices perso des membres donnés.
     *
     * @param list<User> $users
     */
    public function createLibraryQueryBuilder(array $users): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')->orderBy('e.name', 'ASC');

        if ([] === $users) {
            return $qb->andWhere('e.owner IS NULL');
        }

        return $qb
            ->andWhere('e.owner IN (:users) OR e.owner IS NULL')
            ->setParameter('users', $users)
        ;
    }
}
