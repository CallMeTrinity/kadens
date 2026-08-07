<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\MuscleGroup;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Service\TrainingHistory;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'historique en calendrier.
 *
 * **Ce que ces tests protègent avant tout : la frontière entre « une séance a
 * eu lieu » et « voici ce qui a été travaillé ».** La première se lit sur le
 * statut, la seconde sur le réalisé — et il existe des jours qui ont l'une sans
 * l'autre (tout le cardio, tout l'historique d'avant Kadens Live). Les fixtures
 * posent délibérément un jour de course faite dont le prescrit porte des zones :
 * si quelqu'un rebranchait les pastilles sur le prescrit, ce jour se colorerait
 * et le test tomberait.
 *
 * Le reste vérifie que la grille reste une grille : mois vides conservés,
 * débords non comptés deux fois, ordre du plus récent au plus ancien.
 */
final class TrainingHistoryTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private TrainingHistory $history;
    private User $user;

    /** Le « aujourd'hui » de tous les tests : la grille doit être déterministe. */
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->history = static::getContainer()->get(TrainingHistory::class);

        $this->purgeDatabase($this->em);
        $this->user = (new User())->setEmail('athlete-history@example.com')->setPassword('x');
        $this->em->persist($this->user);
        $this->em->flush();

        $this->now = new \DateTimeImmutable('2026-08-07');
    }

    /**
     * Un compte neuf n'a pas d'historique : pas d'années, pas de bornes. C'est
     * l'état vide, à distinguer d'un historique qui existe mais ne contient que
     * des mois creux (cf. le test des mois vides).
     */
    public function testAnAccountWithoutHistoryHasNoYears(): void
    {
        $result = $this->history->calendar($this->user, $this->now);

        self::assertSame([], $result['years']);
        self::assertNull($result['bounds']);
        self::assertSame(0, $result['totals']['sessions']);
    }

    /**
     * Le cœur de la règle. Une sortie course cochée faite compte comme séance
     * (elle a eu lieu) mais ne porte AUCUN groupe : le cardio ne se logue
     * jamais, son prescrit n'est pas une mesure. La colorer serait faire passer
     * une intention pour un fait.
     */
    public function testACardioSessionCountsButCarriesNoMuscleGroup(): void
    {
        $run = $this->exercise('Footing', ActivityType::RUNNING, [TargetArea::QUADRICEPS]);
        $this->schedule($this->workout($run, 'Sortie'), '2026-08-03', ScheduledStatus::DONE);
        $this->em->flush();

        $day = $this->day($this->history->calendar($this->user, $this->now), '2026-08-03');

        self::assertCount(1, $day['sessions'], 'La séance a bien eu lieu.');
        self::assertSame([], $day['sessions'][0]['groups'], 'Le cardio ne se logue pas : rien à colorer.');
        self::assertSame(0, $day['sessions'][0]['workingSets']);
        self::assertTrue($day['sessions'][0]['endurance'], 'Elle doit être reconnue comme une sortie.');
        self::assertSame(ActivityType::RUNNING, $day['sessions'][0]['activity']);
    }

    /**
     * Une séance de salle loguée porte ses groupes, ordonnés par volume
     * décroissant. Ici : 3 séries de squat (jambes) contre 1 de curl (bras),
     * donc jambes d'abord — l'ordre est ce qui rend la première pastille
     * lisible comme « le thème du jour ».
     */
    public function testGroupsComeFromTheLogAndAreOrderedByVolume(): void
    {
        $squat = $this->exercise('Squat', ActivityType::GYM, [TargetArea::QUADRICEPS, TargetArea::GLUTES]);
        $curl = $this->exercise('Curl', ActivityType::GYM, [TargetArea::BICEPS]);

        $workout = $this->workout($squat, 'Bas du corps');
        $scheduled = $this->schedule($workout, '2026-08-04', ScheduledStatus::DONE);

        $this->log($scheduled, $squat, [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ]);
        $this->log($scheduled, $curl, [[SetType::NORMAL, 10, 20.0]], 1);
        $this->em->flush();

        $day = $this->day($this->history->calendar($this->user, $this->now), '2026-08-04');

        self::assertSame([MuscleGroup::LEGS, MuscleGroup::ARMS], $day['sessions'][0]['groups']);
        self::assertSame(4, $day['sessions'][0]['workingSets']);
        self::assertFalse($day['sessions'][0]['endurance'], 'Une séance de salle n\'est pas une sortie.');
    }

    /**
     * Deux zones du MÊME groupe ne comptent pas double. Le squat cible
     * quadriceps ET fessiers, tous deux « jambes » : sans dédoublonnage, un
     * exercice à trois zones de jambes ferait passer les jambes devant un
     * groupe réellement plus travaillé, et l'ordre des pastilles mentirait.
     */
    public function testTwoAreasOfTheSameGroupAreNotCountedTwice(): void
    {
        $squat = $this->exercise('Squat', ActivityType::GYM, [TargetArea::QUADRICEPS, TargetArea::GLUTES, TargetArea::HAMSTRINGS]);
        $row = $this->exercise('Rowing', ActivityType::GYM, [TargetArea::BACK]);

        $scheduled = $this->schedule($this->workout($squat, 'Mixte'), '2026-08-05', ScheduledStatus::DONE);
        $this->log($scheduled, $squat, [[SetType::NORMAL, 5, 100.0]]);
        $this->log($scheduled, $row, [
            [SetType::NORMAL, 10, 60.0],
            [SetType::NORMAL, 10, 60.0],
        ], 1);
        $this->em->flush();

        $day = $this->day($this->history->calendar($this->user, $this->now), '2026-08-05');

        self::assertSame(
            [MuscleGroup::BACK, MuscleGroup::LEGS],
            $day['sessions'][0]['groups'],
            'Le dos a 2 séries, les jambes 1 : trois zones de jambes ne valent pas trois séries.',
        );
    }

    /**
     * Le périmètre du volume est celui du reste des statistiques :
     * l'échauffement n'en est pas, et une série cochée sans valeur non plus
     * (140 kg × 0 rep n'est pas du travail). Un jour qui n'aurait QUE ça
     * compterait comme séance faite, sans pastille.
     */
    public function testWarmupAndUnmeasuredSetsAreOutOfTheVolume(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $scheduled = $this->schedule($this->workout($bench, 'Haut'), '2026-08-06', ScheduledStatus::DONE);

        $this->log($scheduled, $bench, [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, null, 140.0],
            [SetType::NORMAL, 8, 100.0],
        ]);
        $this->em->flush();

        $day = $this->day($this->history->calendar($this->user, $this->now), '2026-08-06');

        self::assertSame(1, $day['sessions'][0]['workingSets'], 'Seule la série chiffrée de travail compte.');
        self::assertSame([MuscleGroup::CHEST], $day['sessions'][0]['groups']);
    }

    /**
     * Un exercice supprimé depuis (FK en SET NULL) a bien porté du volume, mais
     * n'a plus de zones à donner : il compte dans les séries et ne colore rien.
     * Surtout, il ne fait pas planter la page.
     */
    public function testADeletedExerciseCountsButColoursNothing(): void
    {
        // Séance LIBRE (sans `Workout`), pour que rien d'autre que le réalisé ne
        // référence l'exercice : un `PrescribedExercise` le retiendrait, et sa
        // clé étrangère à lui n'est pas en SET NULL.
        $ghost = $this->exercise('Exercice supprimé', ActivityType::GYM, [TargetArea::CHEST]);
        $scheduled = $this->scheduleFree('Séance libre', '2026-08-02');
        $this->log($scheduled, $ghost, [[SetType::NORMAL, 8, 100.0]]);
        $this->em->flush();

        // Vider l'unité de travail AVANT la suppression : sinon le
        // `LoggedExercise` encore en mémoire retient l'`Exercise`, et le flush
        // imbriqué de TombstoneListener le redécouvre comme une entité neuve.
        $id = $ghost->getId();
        $this->em->clear();
        $this->em->remove($this->em->find(Exercise::class, $id));
        $this->em->flush();
        $this->em->clear();

        $day = $this->day($this->history->calendar($this->user, $this->now), '2026-08-02');

        self::assertSame(1, $day['sessions'][0]['workingSets']);
        self::assertSame([], $day['sessions'][0]['groups']);
    }

    /**
     * Deux séances le même jour font un compteur à 2, pas deux cases. Le
     * calendrier n'a qu'une case par jour, et l'assiduité compte les séances.
     */
    public function testTwoSessionsOnTheSameDayCountTwice(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $workout = $this->workout($bench, 'Haut');
        $this->schedule($workout, '2026-08-01', ScheduledStatus::DONE);
        $this->schedule($workout, '2026-08-01', ScheduledStatus::DONE);
        $this->em->flush();

        $result = $this->history->calendar($this->user, $this->now);

        self::assertCount(2, $this->day($result, '2026-08-01')['sessions']);
        self::assertSame(2, $result['totals']['sessions']);
        self::assertSame(1, $result['totals']['activeDays'], 'Deux séances, un seul jour actif.');
    }

    /**
     * Seules les séances FAITES entrent dans l'historique. Une séance manquée ou
     * encore à venir n'a rien à montrer d'un passé : elle laisse sa case vide.
     */
    public function testOnlyDoneSessionsAppear(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $workout = $this->workout($bench, 'Haut');
        $this->schedule($workout, '2026-07-10', ScheduledStatus::MISSED);
        $this->schedule($workout, '2026-08-20', ScheduledStatus::PLANNED);
        $this->em->flush();

        $result = $this->history->calendar($this->user, $this->now);

        self::assertSame(0, $result['totals']['sessions']);
        self::assertSame([], $this->day($result, '2026-07-10')['sessions']);
        self::assertSame([], $this->day($result, '2026-08-20')['sessions']);
    }

    /**
     * Les mois vides entre deux mois pleins sont CONSERVÉS : un trou est une
     * information (le même principe que la rampe de progression). Les mois vont
     * du plus récent au plus ancien, et la grille court jusqu'au mois courant
     * même s'il ne contient rien.
     */
    public function testEmptyMonthsInBetweenAreKeptAndOrderIsNewestFirst(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $workout = $this->workout($bench, 'Haut');
        $this->schedule($workout, '2026-05-10', ScheduledStatus::DONE);
        $this->em->flush();

        $result = $this->history->calendar($this->user, $this->now);
        $labels = [];
        foreach ($result['years'] as $year) {
            foreach ($year['months'] as $month) {
                $labels[] = $month['label'];
            }
        }

        self::assertSame(
            ['août 2026', 'juillet 2026', 'juin 2026', 'mai 2026'],
            $labels,
            'De mai (première séance) à août (mois courant), sans sauter juin ni juillet.',
        );
    }

    /**
     * Les débords de grille (les jours du mois voisin qui complètent la première
     * et la dernière semaine) sont affichés pour garder les colonnes alignées,
     * mais ne comptent QUE pour leur propre mois. Sinon le total annuel
     * compterait deux fois les séances de fin de mois.
     */
    public function testOverflowDaysAreShownButCountedOnlyInTheirOwnMonth(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $workout = $this->workout($bench, 'Haut');
        // Le 1er août 2026 est un samedi : il déborde dans la grille de juillet.
        $this->schedule($workout, '2026-08-01', ScheduledStatus::DONE);
        // Une séance MANQUÉE en juillet : elle ne compte pas comme faite, mais
        // elle fait exister le mois dans la grille (les bornes sont tous statuts
        // confondus). C'est ce qui permet de comparer les deux mois.
        $this->schedule($workout, '2026-07-15', ScheduledStatus::MISSED);
        $this->em->flush();

        $result = $this->history->calendar($this->user, $this->now);

        $august = $this->month($result, 'août 2026');
        $july = $this->month($result, 'juillet 2026');

        self::assertSame(1, $august['sessions']);
        self::assertSame(0, $july['sessions'], 'Le 1er août ne compte pas pour juillet.');
        self::assertSame(1, $result['years'][0]['sessions']);
    }

    /**
     * **Deux séances le même jour restent deux séances**, et chacune garde sa
     * nature : une course le matin, de la salle le soir. Les fondre en une case
     * unique perdrait la moitié de la journée, et il n'y aurait plus qu'un lien
     * là où il en faut deux.
     */
    public function testACardioAndAGymSessionOnTheSameDayAreBothVisible(): void
    {
        $run = $this->exercise('Footing', ActivityType::RUNNING, []);
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);

        $this->schedule($this->workout($run, 'Sortie du matin'), '2026-08-04', ScheduledStatus::DONE);
        $gym = $this->schedule($this->workout($bench, 'Haut du corps'), '2026-08-04', ScheduledStatus::DONE);
        $this->log($gym, $bench, [[SetType::NORMAL, 8, 100.0]]);
        $this->em->flush();

        $sessions = $this->day($this->history->calendar($this->user, $this->now), '2026-08-04')['sessions'];

        self::assertCount(2, $sessions);

        $byTitle = array_column($sessions, null, 'title');

        self::assertTrue($byTitle['Sortie du matin']['endurance']);
        self::assertSame([], $byTitle['Sortie du matin']['groups']);

        self::assertFalse($byTitle['Haut du corps']['endurance']);
        self::assertSame([MuscleGroup::CHEST], $byTitle['Haut du corps']['groups']);
    }

    /**
     * La répartition par nature : chaque séance compte pour UNE, sous son
     * activité dominante, et la somme vaut le total. C'est ce qui la distingue
     * du `activityCounts` de TrainingStats, qui compte une séance dans chacune
     * de ses activités — deux questions, deux chiffres, et celui-ci doit
     * s'additionner.
     */
    public function testSessionsAreTalliedOncePerActivity(): void
    {
        $run = $this->exercise('Footing', ActivityType::RUNNING, []);
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);

        $runWorkout = $this->workout($run, 'Sortie');
        $gymWorkout = $this->workout($bench, 'Haut');

        foreach (['2026-08-01', '2026-08-03', '2026-08-05'] as $date) {
            $this->schedule($gymWorkout, $date, ScheduledStatus::DONE);
        }
        $this->schedule($runWorkout, '2026-08-02', ScheduledStatus::DONE);
        $this->em->flush();

        $totals = $this->history->calendar($this->user, $this->now)['totals'];

        self::assertSame(4, $totals['sessions']);
        self::assertSame(
            [
                ['activity' => ActivityType::GYM, 'sessions' => 3],
                ['activity' => ActivityType::RUNNING, 'sessions' => 1],
            ],
            $totals['byActivity'],
            'La plus pratiquée d\'abord, et la somme vaut le total.',
        );
    }

    /**
     * Une séance mixte relève de son activité DOMINANTE, celle que portent le
     * plus d'exercices : un footing suivi d'un seul exercice de gainage reste
     * une sortie. Sans ce départage, elle compterait deux fois et la somme ne
     * vaudrait plus le total.
     */
    public function testAMixedSessionFallsUnderItsDominantActivity(): void
    {
        $run = $this->exercise('Footing', ActivityType::RUNNING, []);
        $plank = $this->exercise('Gainage', ActivityType::GYM, [TargetArea::ABS]);

        $workout = $this->workout($run, 'Sortie + gainage');
        $this->addExercise($workout, $run);
        $this->addExercise($workout, $plank);
        $this->schedule($workout, '2026-08-04', ScheduledStatus::DONE);
        $this->em->flush();

        $totals = $this->history->calendar($this->user, $this->now)['totals'];

        self::assertSame(1, $totals['sessions']);
        self::assertSame(
            [['activity' => ActivityType::RUNNING, 'sessions' => 1]],
            $totals['byActivity'],
            'Deux exercices de course contre un de salle : c\'est une sortie.',
        );
    }

    /**
     * **Le cas de l'historique importé, et la régression à ne jamais refaire.**
     * `TrainingHistoryImporter` crée ses séances avec `workout = null` — il a le
     * fait, pas l'intention, et il écrit le fait. Ces séances n'ont donc AUCUN
     * prescrit : leur nature ne peut se lire que sur leur réalisé.
     *
     * Lu sur le prescrit seul, un historique de trois cents séances de salle se
     * rangeait entièrement sous « activité inconnue ». C'est ce que ce test
     * empêche de revenir.
     */
    public function testASessionWithoutAProgramTakesItsNatureFromItsLog(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $imported = $this->scheduleFree('Séance importée', '2026-08-04');
        $this->log($imported, $bench, [[SetType::NORMAL, 8, 100.0]]);
        $this->em->flush();

        $result = $this->history->calendar($this->user, $this->now);

        self::assertSame(
            [['activity' => ActivityType::GYM, 'sessions' => 1]],
            $result['totals']['byActivity'],
            'Sans prescrit, le réalisé est la seule source qui dise la nature.',
        );

        $session = $this->day($result, '2026-08-04')['sessions'][0];
        self::assertSame(ActivityType::GYM, $session['activity']);
        self::assertFalse($session['endurance']);
        self::assertSame([MuscleGroup::CHEST], $session['groups']);
    }

    /**
     * Le réalisé ne fait que COMPLÉTER le prescrit, il ne le corrige pas : une
     * séance prescrite en course dont on aurait logué du gainage reste une
     * course. Vouloir l'inverse serait une décision à prendre, pas un effet de
     * bord à laisser arriver.
     */
    public function testThePrescriptionWinsOverTheLogWhenBothExist(): void
    {
        $run = $this->exercise('Footing', ActivityType::RUNNING, []);
        $plank = $this->exercise('Gainage', ActivityType::GYM, [TargetArea::ABS]);

        $outing = $this->schedule($this->workout($run, 'Sortie'), '2026-08-04', ScheduledStatus::DONE);
        $this->log($outing, $plank, [
            [SetType::NORMAL, 30, null],
            [SetType::NORMAL, 30, null],
        ]);
        $this->em->flush();

        $session = $this->day($this->history->calendar($this->user, $this->now), '2026-08-04')['sessions'][0];

        self::assertSame(ActivityType::RUNNING, $session['activity']);
        self::assertTrue($session['endurance']);
    }

    /**
     * Une séance cochée faite et restée VIDE — ni prescrit ni réalisé — n'a
     * aucune nature à déclarer. Elle est comptée à part plutôt que rangée
     * d'office en salle : la mettre ailleurs ferait mentir le décompte, la taire
     * aussi.
     */
    public function testAnEmptySessionIsTalliedApart(): void
    {
        $this->scheduleFree('Séance improvisée', '2026-08-04');
        $this->em->flush();

        $totals = $this->history->calendar($this->user, $this->now)['totals'];

        self::assertSame(1, $totals['sessions']);
        self::assertSame([['activity' => null, 'sessions' => 1]], $totals['byActivity']);
    }

    /**
     * Chaque séance porte de quoi être ouverte : son identifiant et son titre.
     * Sans eux la case ne serait qu'un point de couleur, et toute la page se
     * réduirait à une carte de chaleur.
     */
    public function testEverySessionCarriesWhatItTakesToOpenIt(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $scheduled = $this->schedule($this->workout($bench, 'Haut du corps'), '2026-08-04', ScheduledStatus::DONE);
        $this->em->flush();
        $id = $scheduled->getId();

        $session = $this->day($this->history->calendar($this->user, $this->now), '2026-08-04')['sessions'][0];

        self::assertSame($id, $session['id']);
        self::assertSame('Haut du corps', $session['title']);
    }

    /**
     * **L'invariant de coût, et c'est celui qui rend la page tenable.** Le
     * nombre de requêtes ne dépend PAS de la profondeur de l'historique : la
     * grille se construit en PHP sur quatre lectures, pas en interrogeant la
     * base mois par mois. Deux ans d'historique doivent coûter exactement ce que
     * coûte un mois.
     *
     * Le jour où quelqu'un remettrait une requête dans la boucle des mois
     * (« juste pour récupérer X »), ce test tombe — et c'est le seul endroit qui
     * s'en apercevrait avant la production.
     */
    public function testTheQueryCountDoesNotGrowWithHistoryDepth(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $workout = $this->workout($bench, 'Haut');
        $scheduled = $this->schedule($workout, '2026-07-15', ScheduledStatus::DONE);
        $this->log($scheduled, $bench, [[SetType::NORMAL, 8, 100.0]]);
        $this->em->flush();

        $userId = $this->user->getId();
        $shallow = $this->countQueries(fn () => $this->history->calendar($this->user, $this->now));

        // `countQueries` vide l'unité de travail : les fixtures doivent être
        // reprises avant de continuer à écrire.
        $this->user = $this->em->find(User::class, $userId);
        $bench = $this->em->find(Exercise::class, $bench->getId());
        $workout = $this->em->find(Workout::class, $workout->getId());

        // Deux ans de plus, une séance loguée par mois : la grille passe de deux
        // mois à vingt-six.
        for ($i = 1; $i <= 24; ++$i) {
            $date = (new \DateTimeImmutable('2026-07-15'))->modify(sprintf('-%d months', $i));
            $scheduled = $this->schedule($workout, $date->format('Y-m-d'), ScheduledStatus::DONE);
            $this->log($scheduled, $bench, [[SetType::NORMAL, 8, 100.0]]);
        }
        $this->em->flush();

        $deep = $this->countQueries(fn () => $this->history->calendar($this->user, $this->now));

        $months = 0;
        foreach ($this->history->calendar($this->user, $this->now)['years'] as $year) {
            $months += \count($year['months']);
        }

        self::assertGreaterThan(24, $months, 'La fixture doit bien produire plus de deux ans de grille.');
        self::assertSame($shallow, $deep, sprintf(
            'La page doit coûter le même nombre de requêtes quel que soit l\'historique (%d vs %d).',
            $shallow,
            $deep,
        ));

        // Le chiffre est figé volontairement : bornes, séances faites, leurs
        // activités prescrites, celles de leur réalisé, volume par
        // (séance × exercice), définitions des exercices. Une septième requête
        // qui apparaîtrait est une régression à justifier, pas à entériner.
        self::assertSame(6, $deep);
    }

    /** Chaque semaine fait sept cases, sans quoi les colonnes se décalent. */
    public function testEveryWeekHasSevenCells(): void
    {
        $bench = $this->exercise('Développé couché', ActivityType::GYM, [TargetArea::CHEST]);
        $this->schedule($this->workout($bench, 'Haut'), '2026-08-04', ScheduledStatus::DONE);
        $this->em->flush();

        foreach ($this->month($this->history->calendar($this->user, $this->now), 'août 2026')['weeks'] as $week) {
            self::assertCount(7, $week);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Le nombre de requêtes SQL émises par un appel. L'unité de travail est
     * vidée d'abord : sans ça, les exercices déjà en mémoire seraient servis par
     * l'identity map et la seconde mesure serait plus basse pour de mauvaises
     * raisons.
     */
    private function countQueries(callable $call): int
    {
        $this->em->clear();

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $call();

        return \count($holder->getData()['default'] ?? []);
    }

    /** @return array<string, mixed> */
    private function day(array $result, string $date): array
    {
        foreach ($result['years'] as $year) {
            foreach ($year['months'] as $month) {
                foreach ($month['weeks'] as $week) {
                    foreach ($week as $cell) {
                        if ($cell['inMonth'] && $cell['date']->format('Y-m-d') === $date) {
                            return $cell;
                        }
                    }
                }
            }
        }

        self::fail(sprintf('Aucune case « dans le mois » pour %s.', $date));
    }

    /** @return array<string, mixed> */
    private function month(array $result, string $label): array
    {
        foreach ($result['years'] as $year) {
            foreach ($year['months'] as $month) {
                if ($month['label'] === $label) {
                    return $month;
                }
            }
        }

        self::fail(sprintf('Mois « %s » absent de la grille.', $label));
    }

    /** @param list<TargetArea> $areas */
    private function exercise(string $name, ActivityType $activity, array $areas): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($this->user)
            ->setName($name)
            ->setActivity($activity)
            ->setTargetAreas($areas);

        $this->em->persist($exercise);

        return $exercise;
    }

    private function workout(Exercise $exercise, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($this->user)
            ->setTitle($title)
            ->setSlug(strtolower(preg_replace('/[^a-z]+/i', '-', $title)).'-'.bin2hex(random_bytes(4)));

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(8)->setWeightKg(80.0),
        );
        $workout->addBlock($block);

        $this->em->persist($workout);
        $this->em->persist($block);

        return $workout;
    }

    /** Ajoute un exercice prescrit au premier bloc d'une séance existante. */
    private function addExercise(Workout $workout, Exercise $exercise): void
    {
        $block = $workout->getBlocks()->first();
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition($block->getPrescribedExercises()->count())
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(8)->setWeightKg(80.0),
        );
    }

    private function schedule(Workout $workout, string $date, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);

        $this->em->persist($scheduled);

        return $scheduled;
    }

    /**
     * Une séance datée SANS séance de bibliothèque : ce qu'on crée en partant à
     * l'improviste. Rien ne prescrit rien, donc rien ne retient les exercices
     * qu'on y logue.
     */
    private function scheduleFree(string $title, string $date): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setTitle($title)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);

        $this->em->persist($scheduled);

        return $scheduled;
    }

    /** @param list<array{SetType, int|null, float|null}> $sets */
    private function log(ScheduledWorkout $scheduled, Exercise $exercise, array $sets, int $position = 0): void
    {
        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition($position);

        foreach ($sets as $index => [$type, $reps, $weightKg]) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition($index)
                    ->setSetType($type)
                    ->setReps($reps)
                    ->setWeightKg($weightKg),
            );
        }

        $scheduled->addLoggedExercise($logged);
    }
}
