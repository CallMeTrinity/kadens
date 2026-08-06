<?php

namespace App\Repository;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ScheduledWorkout>
 */
class ScheduledWorkoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledWorkout::class);
    }

    /**
     * Retrouve une séance datée par son identifiant client, sans son contenu.
     * Les endpoints de l'API, eux, passent par `findByUuidWithContentAndLog()` :
     * ils rendent le document complet, et le charger en une fois est ce qui leur
     * évite un N+1 par exercice.
     */
    public function findByUuid(Uuid $uuid): ?ScheduledWorkout
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Séances planifiées d'un utilisateur dans une fenêtre de dates (bornes
     * incluses). Sert au rendu d'une grille de calendrier : on charge d'un coup
     * tout ce que couvre le mois affiché (débords des semaines compris) et on
     * jointe la séance pour éviter N requêtes au rendu.
     *
     * `leftJoin` et non `join` : une séance datée peut n'avoir aucune source
     * (séance libre, ou séance de bibliothèque supprimée depuis). Une jointure
     * interne la ferait disparaître du calendrier au lieu de la montrer.
     *
     * @return list<ScheduledWorkout>
     */
    public function findByOwnerBetween(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w')
            ->leftJoin('s.workout', 'w')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.scheduledDate BETWEEN :start AND :end')
            ->setParameter('owner', $owner)
            ->setParameter('start', $start, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La fenêtre du bootstrap mobile (KL-14) : les séances datées d'un
     * utilisateur entre deux dates, **avec tout leur prescrit ET tout leur
     * réalisé**, sans N+1.
     *
     * **Deux requêtes et pas une seule**, et c'est structurel : le prescrit
     * (`w → b → pe → ps`) et le réalisé (`le → ls`) sont deux collections
     * **sœurs** sous la même séance datée. Les joindre dans la même requête en
     * ferait le produit cartésien — quinze séries prescrites et douze séries
     * réalisées donneraient cent quatre-vingts lignes à hydrater pour un seul
     * exercice. Chaque branche est en revanche une **chaîne**, donc sans risque :
     * on peut y descendre aussi profond qu'on veut. La seconde requête retombe
     * sur les mêmes entités gérées, Doctrine les complète en place.
     *
     * @return list<ScheduledWorkout>
     */
    public function findWindowWithContentAndLog(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $prescribed = $this->withPrescribed($this->windowQueryBuilder($owner, $from, $to))
            ->getQuery()
            ->getResult();

        // Même fenêtre, branche sœur : le résultat est ignoré, il ne sert qu'à
        // remplir les collections des entités déjà gérées ci-dessus.
        $this->withLog($this->windowQueryBuilder($owner, $from, $to))
            ->getQuery()
            ->getResult();

        return $prescribed;
    }

    /**
     * La même chose pour **une** séance datée, désignée par son identifiant
     * client : ce que rend `GET /api/schedule/{uuid}` (KL-15) et ce que relit
     * `PUT /api/schedule/{uuid}` (KL-16).
     *
     * Les deux jointures sont celles de la fenêtre, au mot près, parce qu'elles
     * sont écrites une seule fois (`withPrescribed` / `withLog`). Deux
     * définitions de « avec tout son contenu » auraient fini par diverger, et la
     * promesse de KL-15 — *structure identique à celle du bootstrap* — se serait
     * dégradée en N+1 plutôt qu'en erreur.
     */
    public function findByUuidWithContentAndLog(Uuid $uuid): ?ScheduledWorkout
    {
        $found = $this->withPrescribed($this->uuidQueryBuilder($uuid))
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $found) {
            return null;
        }

        $this->withLog($this->uuidQueryBuilder($uuid))
            ->getQuery()
            ->getResult();

        return $found;
    }

    private function windowQueryBuilder(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.scheduledDate BETWEEN :from AND :to')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
        ;
    }

    private function uuidQueryBuilder(Uuid $uuid): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.uuid = :uuid')
            ->setParameter('uuid', $uuid)
        ;
    }

    /** La branche du PRESCRIT : une chaîne, donc aussi profonde qu'on veut. */
    private function withPrescribed(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->addSelect('w', 'b', 'pe', 'ps', 'e')
            ->leftJoin('s.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.detailedSets', 'ps')
            ->leftJoin('pe.exercise', 'e')
        ;
    }

    /** La branche SŒUR, le réalisé. Jamais dans la même requête que l'autre. */
    private function withLog(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->addSelect('le', 'ls')
            ->leftJoin('s.loggedExercises', 'le')
            ->leftJoin('le.loggedSets', 'ls')
        ;
    }

    /**
     * Nombre de séances planifiées par statut pour un utilisateur sur une fenêtre
     * de dates (bornes incluses). Alimente la vue de synthèse « prévu vs réalisé »
     * (Phase 7) : une seule requête agrégée, pas d'hydratation d'entités.
     *
     * @return array<string, int> clés = valeurs de ScheduledStatus, valeurs = compte.
     *                            Les statuts absents sur la période valent 0.
     */
    public function countByStatusForOwnerBetween(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->countByStatusForOwnerIn($owner, $start, $end);
    }

    /**
     * Nombre de séances par statut pour un utilisateur, sur toute la période (pas
     * de borne de date). Alimente l'observance « tous temps » et le total de
     * séances faites de la page profil. Une seule requête agrégée, pas d'hydratation.
     *
     * @return array<string, int> clés = valeurs de ScheduledStatus, valeurs = compte.
     */
    public function countByStatusForOwner(User $owner): array
    {
        return $this->countByStatusForOwnerIn($owner, null, null);
    }

    /**
     * La forme générale des deux lectures ci-dessus : les bornes sont
     * **facultatives**, parce qu'une fenêtre de statistiques peut ne pas en
     * avoir (« depuis le début »). Une seule définition de « compter par
     * statut », donc aucun risque que la fenêtre du mois et celle des six mois
     * comptent différemment.
     *
     * @return array<string, int>
     */
    public function countByStatusForOwnerIn(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.status AS status', 'COUNT(s.id) AS cnt')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('s.status');

        $this->applyDateWindow($qb, $start, $end);

        return $this->mapStatusCounts($qb->getQuery()->getResult());
    }

    /**
     * Les dates des séances FAITES d'un utilisateur sur une fenêtre, une ligne
     * par séance (donc deux lignes pour un jour à deux séances). Projection
     * scalaire : ce que coûte « depuis le début » ici, c'est un tableau de
     * dates, pas un historique hydraté.
     *
     * C'est la matière de toute la régularité — nombre de séances, jours
     * actifs, séances par semaine, série de semaines tenues, meilleur mois —
     * calculée en PHP sur ce seul tableau plutôt qu'en cinq requêtes qui
     * finiraient par ne plus compter la même chose.
     *
     * @return list<\DateTimeImmutable> triées du plus ancien au plus récent
     */
    public function doneDatesForOwner(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.scheduledDate AS date')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.status = :done')
            ->setParameter('owner', $owner)
            ->setParameter('done', \App\Enum\ScheduledStatus::DONE)
            ->orderBy('s.scheduledDate', 'ASC');

        $this->applyDateWindow($qb, $start, $end);

        return array_map(
            static fn (array $row): \DateTimeImmutable => $row['date'] instanceof \DateTimeImmutable
                ? $row['date']
                : new \DateTimeImmutable((string) $row['date']),
            $qb->getQuery()->getArrayResult(),
        );
    }

    /**
     * Première et dernière date planifiée d'un utilisateur, tous statuts
     * confondus. Deux usages, une requête : borner la fenêtre « depuis le
     * début » (dont la durée est celle de l'historique réel) et remplir la
     * liste des mois du sélecteur.
     *
     * @return array{first: \DateTimeImmutable, last: \DateTimeImmutable}|null null si l'utilisateur n'a jamais rien planifié
     */
    public function dateBoundsForOwner(User $owner): ?array
    {
        /** @var array{first: mixed, last: mixed}|null $row */
        $row = $this->createQueryBuilder('s')
            ->select('MIN(s.scheduledDate) AS first', 'MAX(s.scheduledDate) AS last')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $row || null === $row['first'] || null === $row['last']) {
            return null;
        }

        $toDate = static fn (mixed $v): \DateTimeImmutable => $v instanceof \DateTimeImmutable
            ? $v
            : new \DateTimeImmutable((string) $v);

        return ['first' => $toDate($row['first']), 'last' => $toDate($row['last'])];
    }

    /**
     * Séances FAITES d'un utilisateur, avec tout leur contenu fetch-joint
     * (blocs -> exercices prescrits -> exercice), pour agréger le volume réalisé
     * sur l'historique (tonnage, distances) sans N+1. Alimente ProfileStats.
     *
     * **Le seul chemin hydratant des statistiques**, et le seul qui doive rester
     * bornable : il porte le volume d'endurance, qui ne se logue jamais (règle du
     * projet) et se lit donc sur le prescrit des séances cochées faites. Le reste
     * — tonnage, séries, records — passe par les agrégats scalaires de
     * LoggedSetRepository. Ses appelants n'en font qu'UNE passe et en tirent
     * toutes leurs lectures.
     *
     * @return list<ScheduledWorkout>
     */
    public function findDoneWithContentForOwner(User $owner, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('w', 'b', 'pe', 'e')
            ->leftJoin('s.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'e')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.status = :done')
            ->setParameter('owner', $owner)
            ->setParameter('done', \App\Enum\ScheduledStatus::DONE);

        $this->applyDateWindow($qb, $start, $end);

        return $qb->getQuery()->getResult();
    }

    /**
     * Les séances **cochées « faite » qui ne portent aucun réalisé**, avec leur
     * prescrit fetch-joint jusqu'aux séries détaillées. C'est l'assiette de
     * `app:log:backfill` (`LogBackfiller`), et rien d'autre ne s'en sert.
     *
     * Trois filtres, trois raisons :
     *
     * - `s.loggedExercises IS EMPTY` — une déduction ne remplace jamais un fait.
     *   Le filtre est en DQL plutôt qu'en PHP pour ne pas descendre des milliers
     *   de séances déjà loguées et les écarter une par une.
     * - une jointure **INTERNE** sur `s.workout` — une séance libre n'a pas de
     *   prescrit d'où déduire quoi que ce soit, et une séance dont la source a
     *   été supprimée (`SET NULL`) non plus.
     * - `s.scheduledDate <= :until` — le passé, aujourd'hui compris : une séance
     *   cochée ce matin est aussi légitime que celle d'hier.
     *
     * @return list<ScheduledWorkout>
     */
    public function findDoneWithoutLog(?User $owner, ?\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('w', 'b', 'pe', 'e', 'ps')
            ->join('s.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'e')
            ->leftJoin('pe.detailedSets', 'ps')
            ->andWhere('s.status = :done')
            ->andWhere('s.scheduledDate <= :until')
            ->andWhere('s.loggedExercises IS EMPTY')
            ->setParameter('done', \App\Enum\ScheduledStatus::DONE)
            ->setParameter('until', $until)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        if (null !== $since) {
            $qb->andWhere('s.scheduledDate >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Toutes les séances datées d'un utilisateur, contenu fetch-joint
     * (blocs -> exercices prescrits -> exercice), triées par date. Alimente le flux
     * ICS « tout le calendrier » : PlanFlattener bâtit la description de chaque
     * événement sans N+1.
     *
     * @return list<ScheduledWorkout>
     */
    public function findAllForOwnerWithContent(User $owner): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w', 'b', 'pe', 'e')
            ->leftJoin('s.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'e')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances datées issues d'un plan pour un utilisateur, contenu fetch-joint.
     * Variante « avec contenu » de findBySourcePlanTemplateForOwner : alimente le
     * flux ICS restreint à un plan instancié.
     *
     * @return list<ScheduledWorkout>
     */
    public function findBySourcePlanTemplateForOwnerWithContent(PlanTemplate $template, User $owner): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w', 'b', 'pe', 'e')
            ->leftJoin('s.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'e')
            ->andWhere('s.sourcePlanTemplate = :template')
            ->andWhere('s.owner = :owner')
            ->setParameter('template', $template)
            ->setParameter('owner', $owner)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Répartition par statut, regroupée par plan source, pour un utilisateur.
     * Le plan source est nullable (séance isolée, ou plan supprimé qui a mis la
     * FK à NULL) : ces séances retombent dans un bucket « hors plan ».
     *
     * @return list<array{planId: int|null, planTitle: string|null, counts: array<string, int>}>
     */
    public function statusCountsByPlanForOwner(User $owner, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select(
                'IDENTITY(s.sourcePlanTemplate) AS planId',
                'p.title AS planTitle',
                's.status AS status',
                'COUNT(s.id) AS cnt',
            )
            ->leftJoin('s.sourcePlanTemplate', 'p')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('planId')
            ->addGroupBy('p.title')
            ->addGroupBy('s.status');

        $this->applyDateWindow($qb, $start, $end);

        $rows = $qb->getQuery()->getResult();

        // Regroupe les lignes (planId, status, cnt) en un bucket par plan.
        $byPlan = [];
        foreach ($rows as $row) {
            $planId = null !== $row['planId'] ? (int) $row['planId'] : null;
            $key = $planId ?? 0; // 0 = bucket « hors plan »
            if (!isset($byPlan[$key])) {
                $byPlan[$key] = [
                    'planId' => $planId,
                    'planTitle' => $row['planTitle'],
                    'counts' => $this->emptyStatusCounts(),
                ];
            }
            $byPlan[$key]['counts'][$this->statusValue($row['status'])] += (int) $row['cnt'];
        }

        return array_values($byPlan);
    }

    /**
     * Séances datées issues d'un plan pour un utilisateur (l'« instance vivante »
     * de ce plan sur son calendrier). Sert au resync : ajouter les cases nouvelles,
     * retrouver l'ancre. La séance (copie locale) est jointe pour le rendu.
     *
     * @return list<ScheduledWorkout>
     */
    public function findBySourcePlanTemplateForOwner(PlanTemplate $template, User $owner): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w')
            ->leftJoin('s.workout', 'w')
            ->andWhere('s.sourcePlanTemplate = :template')
            ->andWhere('s.owner = :owner')
            ->setParameter('template', $template)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les `limit` dernières séances datées d'un utilisateur qui portent
     * réellement du réalisé, avec tout ce réalisé joint. Alimente la fiche
     * athlète du coach (KL-45), qui lit ce que son athlète a fait.
     *
     * **Deux requêtes, et la première borne.** Une jointure de collection et un
     * `setMaxResults` ne se combinent pas : la limite porterait sur les lignes
     * hydratées, donc sur des séries, et rendrait un nombre imprévisible de
     * séances. On borne donc d'abord les séances (lignes distinctes), puis on lit
     * le réalisé de celles-là seulement.
     *
     * Le filtre est l'existence d'un `LoggedExercise`, pas le statut : une séance
     * simplement cochée « faite » n'a rien à montrer ici, et une séance encore
     * `PLANNED` dont la synchro a déjà déposé des séries en a.
     *
     * @return list<ScheduledWorkout>
     */
    public function findRecentLoggedForOwner(User $owner, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $sessions = $this->createQueryBuilder('s')
            ->select('DISTINCT s.id AS id', 's.scheduledDate AS date')
            ->join('s.loggedExercises', 'le')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('s.scheduledDate', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        if ([] === $sessions) {
            return [];
        }

        return $this->createQueryBuilder('s')
            // `le.exercise` est joint parce que LogMetrics lit les zones
            // travaillées de la définition : sans lui, un N+1 par exercice.
            ->addSelect('le', 'ls', 'e')
            ->leftJoin('s.loggedExercises', 'le')
            ->leftJoin('le.loggedSets', 'ls')
            ->leftJoin('le.exercise', 'e')
            ->andWhere('s.id IN (:sessions)')
            ->setParameter('sessions', array_map(static fn (array $row): int => (int) $row['id'], $sessions))
            ->orderBy('s.scheduledDate', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les **ancres d'instanciation** d'un plan chez un utilisateur, la plus
     * récente d'abord. Une trame n'a pas de dates : c'est l'ancre qui dit *quelle
     * fois* on regarde quand on superpose le réalisé au prévu (KL-49).
     *
     * `planAnchorDate` peut être **nulle** — une instanciation antérieure au champ
     * n'en porte pas — et cette valeur est renvoyée comme les autres : c'est une
     * série de séances bien réelle, l'écarter la rendrait invisible. MariaDB place
     * les NULL en fin de tri décroissant, donc au bon endroit : la plus ancienne.
     *
     * @return list<\DateTimeImmutable|null>
     */
    public function findPlanAnchorsForOwner(PlanTemplate $template, User $owner): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.planAnchorDate AS anchor')
            ->andWhere('s.sourcePlanTemplate = :template')
            ->andWhere('s.owner = :owner')
            ->setParameter('template', $template)
            ->setParameter('owner', $owner)
            ->orderBy('anchor', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): ?\DateTimeImmutable {
            $anchor = $row['anchor'];

            return match (true) {
                null === $anchor => null,
                $anchor instanceof \DateTimeImmutable => $anchor,
                default => new \DateTimeImmutable((string) $anchor),
            };
        }, $rows);
    }

    /**
     * Les séances datées d'**une** instanciation, avec leur réalisé et leur case
     * d'origine. C'est la matière de la superposition prévu/réalisé (KL-49).
     *
     * Seule la branche du réalisé est jointe, jamais celle du prescrit : le prévu
     * se lit sur la trame elle-même (`PlanItem → Workout`), pas sur ses copies
     * datées, et joindre les deux collections sœurs sous la même séance en ferait
     * le produit cartésien (cf. `findWindowWithContentAndLog`).
     *
     * @return list<ScheduledWorkout>
     */
    public function findPlanRunWithLog(PlanTemplate $template, User $owner, ?\DateTimeImmutable $anchor): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('le', 'ls', 'e', 'pi')
            ->leftJoin('s.loggedExercises', 'le')
            ->leftJoin('le.loggedSets', 'ls')
            ->leftJoin('le.exercise', 'e')
            ->leftJoin('s.sourcePlanItem', 'pi')
            ->andWhere('s.sourcePlanTemplate = :template')
            ->andWhere('s.owner = :owner')
            ->setParameter('template', $template)
            ->setParameter('owner', $owner)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if (null === $anchor) {
            $qb->andWhere('s.planAnchorDate IS NULL');
        } else {
            $qb->andWhere('s.planAnchorDate = :anchor')
                ->setParameter('anchor', $anchor, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Plans actuellement posés sur le calendrier de l'utilisateur (au moins une
     * séance datée en provient), dédupliqués et triés par titre. Alimente le
     * retrait rapide d'un plan instancié depuis le calendrier.
     *
     * @return list<PlanTemplate>
     */
    public function findInstantiatedPlansForOwner(User $owner): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->distinct()
            ->from(PlanTemplate::class, 'p')
            ->innerJoin(ScheduledWorkout::class, 's', 'WITH', 's.sourcePlanTemplate = p')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances datées issues d'une case précise du plan. Sert au retrait d'une case :
     * on retire les séances encore PLANNED, on préserve celles DONE/MISSED.
     *
     * @return list<ScheduledWorkout>
     */
    public function findBySourcePlanItem(PlanItem $item): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.sourcePlanItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();
    }

    /**
     * Ajoute les bornes de date d'une fenêtre de statistiques, chacune
     * indépendamment facultative.
     *
     * Deux clauses `>=` / `<=` plutôt qu'un `BETWEEN` : la fenêtre « depuis le
     * début » n'a pas de borne basse, et un `BETWEEN` obligerait à lui inventer
     * une date de départ. Le type DATE_IMMUTABLE est passé explicitement — sans
     * lui, Doctrine sérialiserait l'heure et une séance du jour même, comparée à
     * une fin de journée à 23:59:59, tomberait du bon côté par chance plutôt que
     * par contrat.
     */
    private function applyDateWindow(QueryBuilder $qb, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): void
    {
        if (null !== $start) {
            $qb->andWhere('s.scheduledDate >= :windowStart')
                ->setParameter('windowStart', $start, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }

        if (null !== $end) {
            $qb->andWhere('s.scheduledDate <= :windowEnd')
                ->setParameter('windowEnd', $end, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE);
        }
    }

    /**
     * @param list<array{status: mixed, cnt: mixed}> $rows
     *
     * @return array<string, int>
     */
    private function mapStatusCounts(array $rows): array
    {
        $counts = $this->emptyStatusCounts();
        foreach ($rows as $row) {
            $counts[$this->statusValue($row['status'])] += (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Base à zéro pour tous les statuts, dans l'ordre de l'enum.
     *
     * @return array<string, int>
     */
    private function emptyStatusCounts(): array
    {
        $counts = [];
        foreach (\App\Enum\ScheduledStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        return $counts;
    }

    /**
     * Normalise la valeur de statut renvoyée par DQL (enum ou chaîne backing).
     */
    private function statusValue(mixed $status): string
    {
        return $status instanceof \App\Enum\ScheduledStatus ? $status->value : (string) $status;
    }
}
