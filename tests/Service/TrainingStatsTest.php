<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Enum\TargetRegion;
use App\Service\StatsPeriod;
use App\Service\TrainingStats;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le moteur des statistiques d'entraînement.
 *
 * **Ce que ces tests protègent avant tout : la règle de provenance.** Le
 * tonnage vient du RÉALISÉ (séries loguées), les distances du PRESCRIT des
 * séances faites. Les deux sont volontairement distincts dans les fixtures — le
 * prescrit de salle est chargé plus lourd que le réalisé — pour qu'un jour où
 * quelqu'un rebrancherait le volume de salle sur `WorkoutMetrics`, le chiffre
 * change et le test tombe. C'est la seule façon de vérifier qu'on lit bien ce
 * qu'on prétend lire.
 *
 * Le reste vérifie que la fenêtre BORNE réellement : une séance hors fenêtre ne
 * doit apparaître dans aucun agrégat, et le passé antérieur doit rester
 * visible pour juger un record.
 */
final class TrainingStatsTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private TrainingStats $stats;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->stats = static::getContainer()->get(TrainingStats::class);

        $this->purgeDatabase($this->em);
        $this->user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->user);
        $this->em->flush();

        $this->seed();
    }

    /**
     * Le cœur de la règle : le tonnage de salle est celui qui a été SOULEVÉ, pas
     * celui qui avait été écrit. Le prescrit vaut 3×8×80 = 1920 kg, le réalisé
     * 2×8×100 = 1600 kg (l'échauffement est hors volume). C'est 1600 qu'on doit
     * lire, sinon on affiche une intention pour un fait.
     */
    public function testGymVolumeComesFromTheLogNotFromThePrescription(): void
    {
        $gym = $this->stats->over($this->user, StatsPeriod::month(2026, 7))['volume']['gym'];

        self::assertSame(1600.0, $gym['tonnageKg']);
        self::assertSame(2, $gym['workingSets'], "L'échauffement n'est pas du volume de travail.");
        self::assertSame(1, $gym['sessions']);
    }

    /**
     * Le pendant : l'endurance se lit sur le prescrit, parce qu'elle ne se logue
     * jamais (règle du projet). Lui appliquer la règle du réalisé la ferait
     * simplement disparaître de la page.
     */
    public function testEnduranceVolumeComesFromThePrescriptionOfDoneSessions(): void
    {
        $volume = $this->stats->over($this->user, StatsPeriod::month(2026, 7))['volume'];

        self::assertSame(5000, $volume['running']['meters']);
        self::assertSame(1500, $volume['running']['seconds']);
        self::assertSame(1, $volume['running']['sessions']);
        self::assertSame('5 km', $volume['running']['distanceLabel']);
    }

    /**
     * Une séance cochée « faite » sans aucune série loguée compte en assiduité
     * et en régularité, et ne porte pas de tonnage. C'est exact, pas cassé :
     * elle a bien eu lieu, on ne sait simplement pas ce qu'elle pesait.
     */
    public function testASessionWithoutLogStillCountsAsDone(): void
    {
        $stats = $this->stats->over($this->user, StatsPeriod::month(2026, 7));

        // Deux faites (la salle loguée, la sortie course), une manquée, une prévue.
        self::assertSame(2, $stats['adherence']['done']);
        self::assertSame(1, $stats['adherence']['missed']);
        self::assertSame(1, $stats['adherence']['planned']);
        // L'observance porte sur les séances ÉCHUES : 2 sur 3, pas 2 sur 4.
        self::assertEqualsWithDelta(2 / 3, $stats['adherence']['adherence'], 0.0001);

        self::assertSame(2, $stats['regularity']['sessions']);
        self::assertSame(2, $stats['regularity']['activeDays']);
    }

    /** La fenêtre borne : la séance de juin n'entre dans aucun agrégat de juillet. */
    public function testTheWindowExcludesWhatFallsOutsideIt(): void
    {
        $july = $this->stats->over($this->user, StatsPeriod::month(2026, 7));
        $allTime = $this->stats->over($this->user, StatsPeriod::allTime(new \DateTimeImmutable('2026-08-06')));

        self::assertSame(1600.0, $july['volume']['gym']['tonnageKg']);
        // Juin ajoute 5×90 = 450 kg.
        self::assertSame(2050.0, $allTime['volume']['gym']['tonnageKg']);
        self::assertSame(3, $allTime['adherence']['done']);
    }

    /**
     * Un record est un fait relatif à un passé, et ce passé vit HORS de la
     * fenêtre. Juin a chargé 90 kg, juillet 100 : c'est un record de +10, même
     * si juin n'apparaît nulle part ailleurs dans les chiffres de juillet.
     */
    public function testANewRecordIsJudgedAgainstWhatPrecedesTheWindow(): void
    {
        $records = $this->stats->over($this->user, StatsPeriod::month(2026, 7))['records'];

        self::assertTrue($records['comparable']);
        self::assertSame('Développé couché', $records['top'][0]['name']);
        self::assertSame(100.0, $records['top'][0]['weightKg']);

        self::assertCount(1, $records['new']);
        self::assertSame(90.0, $records['new'][0]['previousKg']);
        self::assertSame(10.0, $records['new'][0]['gainKg']);
    }

    /**
     * « Depuis le début » n'a pas de passé antérieur : tous ses maximums sont
     * des records par construction. On le déclare non comparable plutôt que
     * d'afficher une liste de faux exploits.
     */
    public function testAllTimeHasNoNewRecords(): void
    {
        $records = $this->stats->over($this->user, StatsPeriod::allTime(new \DateTimeImmutable('2026-08-06')))['records'];

        self::assertFalse($records['comparable']);
        self::assertSame([], $records['new']);
        self::assertNotEmpty($records['top'], 'Le classement des charges, lui, reste.');
    }

    /**
     * Une série cochée sans aucune valeur ne mesure rien : elle n'entre ni dans
     * le décompte de séries, ni dans la moyenne par séance, ni dans le
     * classement des charges — la barre était pourtant à 140 kg dans la
     * fixture, ce qui en ferait un record si le filtre lâchait. Le SQL des
     * agrégats et `LoggedSet::countsAsWorking()` disent bien la même chose.
     */
    public function testUnmeasuredSetIsExcludedFromEveryGymFigure(): void
    {
        $stats = $this->stats->over($this->user, StatsPeriod::month(2026, 7));
        $gym = $stats['volume']['gym'];

        self::assertSame(2, $gym['workingSets']);
        self::assertSame(1600.0, $gym['tonnageKg']);
        self::assertSame(2.0, $gym['perSessionSets']);
        self::assertSame(100.0, $stats['records']['top'][0]['weightKg'], 'Une barre chargée mais non soulevée n\'est pas une performance.');
    }

    /**
     * La ventilation par région se lit sur les zones de la DÉFINITION en
     * bibliothèque, croisées avec les séries réellement loguées. L'échauffement
     * en est exclu comme partout, la série non chiffrée aussi.
     */
    public function testRegionBreakdownUsesLoggedWorkingSets(): void
    {
        $regions = $this->stats->over($this->user, StatsPeriod::month(2026, 7))['regions'];

        self::assertCount(1, $regions);
        self::assertSame(TargetRegion::UPPER_BODY, $regions[0]['region']);
        self::assertSame(2, $regions[0]['sets']);
    }

    /** L'observance se ventile par plan, le « hors plan » compris. */
    public function testAdherenceIsBrokenDownByPlan(): void
    {
        $plans = $this->stats->over($this->user, StatsPeriod::month(2026, 7))['plans'];

        $titles = array_column($plans, 'planTitle');
        self::assertContains('Bloc force', $titles);
        self::assertContains('Hors plan', $titles);

        $bloc = $plans[array_search('Bloc force', $titles, true)];
        self::assertSame(1, $bloc['done']);
        self::assertSame(1, $bloc['missed']);
    }

    /**
     * La rampe garde ses trous : un mois se trace semaine par semaine, y compris
     * les semaines vides. Les supprimer collerait deux semaines distantes l'une
     * à côté de l'autre.
     */
    public function testProgressionKeepsEmptyBuckets(): void
    {
        $prog = $this->stats->over($this->user, StatsPeriod::month(2026, 7))['progression'];

        self::assertSame('week', $prog['granularity']);
        // Juillet 2026 touche cinq semaines ISO (du lundi 29/06 au 31/07).
        self::assertCount(5, $prog['points']);
        self::assertTrue($prog['hasVolume']);

        $withVolume = array_filter($prog['points'], static fn (array $p): bool => $p['tonnageHeightPct'] > 0);
        self::assertCount(1, $withVolume, 'Une seule semaine porte du volume ; les autres restent tracées à zéro.');
    }

    /** Les mois du sélecteur couvrent tout l'historique, mois vides compris. */
    /**
     * Régression : les semaines se comparent par leur VALEUR, jamais par
     * identité d'instance. `modify()` rend un nouvel objet, donc un `===` sur
     * deux `DateTimeImmutable` est toujours faux et toute série retombait à 1,
     * même avec un historique de plusieurs semaines d'affilée.
     *
     * L'historique porte trois semaines faites : W24 (juin), puis W29 et W30
     * qui se suivent. Le record est donc de deux, pas d'une.
     */
    public function testConsecutiveWeeksAreCountedAsAStreak(): void
    {
        $regularity = $this->stats->over(
            $this->user,
            StatsPeriod::allTime(new \DateTimeImmutable('2026-08-06')),
        )['regularity'];

        self::assertSame(2, $regularity['bestStreak']);
        // Fin de fenêtre au 6 août : la dernière séance date de deux semaines
        // pleines, au-delà de la tolérance. La série est bien interrompue.
        self::assertSame(0, $regularity['currentStreak']);
    }

    /**
     * L'autre moitié de la même règle : lue dans la foulée, la série court
     * jusqu'à la fenêtre. Fin au 27 juillet, dernière séance le 20 : la semaine
     * courante vide est tolérée, et la suite W29→W30 tient.
     */
    public function testCurrentStreakToleratesAnEmptyCurrentWeek(): void
    {
        $regularity = $this->stats->over(
            $this->user,
            StatsPeriod::allTime(new \DateTimeImmutable('2026-07-27')),
        )['regularity'];

        self::assertSame(2, $regularity['currentStreak']);
    }

    public function testAvailableMonthsSpanTheWholeHistoryMostRecentFirst(): void
    {
        $months = $this->stats->availableMonths($this->user, new \DateTimeImmutable('2026-08-06'));

        self::assertSame(['2026-08', '2026-07', '2026-06'], array_column($months, 'value'));
        self::assertSame('août 2026', $months[0]['label']);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * Un historique volontairement hétérogène : de la salle loguée, de la
     * course qui ne l'est pas, une séance manquée, une à venir, et un passé
     * antérieur à la fenêtre pour donner un record à battre.
     */
    private function seed(): void
    {
        $bench = (new Exercise())
            ->setOwner($this->user)
            ->setName('Développé couché')
            ->setActivity(ActivityType::GYM)
            ->setTargetAreas([TargetArea::CHEST]);
        $this->em->persist($bench);

        $run = (new Exercise())
            ->setOwner($this->user)
            ->setName('Footing')
            ->setActivity(ActivityType::RUNNING);
        $this->em->persist($run);

        $plan = (new PlanTemplate())
            ->setOwner($this->user)
            ->setTitle('Bloc force')
            ->setSlug('bloc-force-'.bin2hex(random_bytes(4)))
            ->setDurationWeeks(4);
        $this->em->persist($plan);

        // --- Juin : le passé antérieur à la fenêtre. 5 × 90 kg = 450 kg.
        $june = $this->gymWorkout($bench, 'Haut du corps (juin)');
        $this->schedule($june, '2026-06-10', ScheduledStatus::DONE, [
            [SetType::NORMAL, 5, 90.0],
        ]);

        // --- Juillet, salle : prescrit 3×8×80 (1920 kg), réalisé 2×8×100
        //     (1600 kg) plus un échauffement qui ne compte pas. Les deux
        //     chiffres DOIVENT différer, c'est tout l'objet du test.
        //     La quatrième série est cochée sans aucune valeur, la barre chargée
        //     à 140 : elle ne doit RIEN peser nulle part, pas même en record.
        $july = $this->gymWorkout($bench, 'Haut du corps');
        $this->schedule($july, '2026-07-15', ScheduledStatus::DONE, [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 8, 100.0],
            [SetType::NORMAL, 8, 100.0],
            [SetType::NORMAL, null, 140.0],
        ], $plan);

        // --- Juillet, course : faite, jamais loguée. 5 km en 25 min.
        $outing = (new Workout())
            ->setOwner($this->user)
            ->setTitle('Footing tranquille')
            ->setSlug('footing-'.bin2hex(random_bytes(4)));
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($run)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                ->setDistanceMeters(5000)
                ->setDurationSeconds(1500),
        );
        $outing->addBlock($block);
        $this->em->persist($outing);
        $this->em->persist($block);
        $this->schedule($outing, '2026-07-20', ScheduledStatus::DONE);

        // --- Une manquée et une à venir : l'observance a besoin des trois états.
        $this->schedule($july, '2026-07-22', ScheduledStatus::MISSED, [], $plan);
        $this->schedule($july, '2026-07-25', ScheduledStatus::PLANNED);

        $this->em->flush();
    }

    private function gymWorkout(Exercise $exercise, string $title): Workout
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

    /**
     * @param list<array{SetType, int|null, float|null}> $sets
     */
    private function schedule(Workout $workout, string $date, ScheduledStatus $status, array $sets = [], ?PlanTemplate $plan = null): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);

        if (null !== $plan) {
            $scheduled->setSourcePlanTemplate($plan);
        }

        if ([] !== $sets) {
            $logged = (new LoggedExercise())
                ->setExerciseName('Développé couché')
                ->setExercise($workout->getBlocks()->first()->getPrescribedExercises()->first()->getExercise())
                ->setPosition(0);

            foreach ($sets as $position => [$type, $reps, $weightKg]) {
                $logged->addLoggedSet(
                    (new LoggedSet())
                        ->setPosition($position)
                        ->setSetType($type)
                        ->setReps($reps)
                        ->setWeightKg($weightKg),
                );
            }

            $scheduled->addLoggedExercise($logged);
        }

        $this->em->persist($scheduled);

        return $scheduled;
    }
}
