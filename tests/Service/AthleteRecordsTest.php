<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\AthleteRecords;
use App\Service\PerformanceHistory;
use App\Service\UnitFormatter;
use App\Tests\PurgesDatabase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La réconciliation « ce que j'annonce » / « ce que j'ai fait ». Elle lit en
 * base, comme PerformanceHistory dont elle hérite le périmètre : un test à
 * double ne dirait rien de l'échauffement exclu ni de l'isolation par
 * utilisateur, qui sont justement ce qui pourrait casser.
 */
final class AthleteRecordsTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private AthleteRecords $records;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Câblé à la main : le service est injecté dans ProfileStats et jamais
        // tiré du conteneur, qui l'inline donc.
        $this->records = new AthleteRecords(
            $this->em->getRepository(Exercise::class),
            new PerformanceHistory($this->em->getRepository(LoggedSet::class), new UnitFormatter()),
            new UnitFormatter(),
        );

        $this->purgeDatabase($this->em);
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase($this->em);

        parent::tearDown();
    }

    /** Rien de logué : la fiche affiche ce qui a été saisi, sans provenance. */
    public function testDeclaredRecordStandsWithoutAnyLog(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setSquat1rmKg(140.0);
        $this->em->flush();

        $squat = $this->row($user, 'Squat');

        self::assertSame('140 kg', $squat['value']);
        self::assertNull($squat['note'], 'Une valeur déclarée ne porte pas de date : son absence est ce qui la signale.');
        self::assertNull($squat['exerciseId']);
    }

    /**
     * Le point du ticket : une série plus lourde que le record déclaré le
     * remplace, et la ligne dit d'où vient le nouveau chiffre.
     */
    public function testHeavierLoggedSetTakesOverTheDeclaredRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setSquat1rmKg(140.0);
        $this->em->flush();

        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');
        $this->log($user, $squat, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 1, 145.0]]);

        $row = $this->row($user, 'Squat');

        self::assertSame('145 kg', $row['value']);
        self::assertSame('1 rep · 12/08/26', $row['note']);
        self::assertSame($squat->getId(), $row['exerciseId'], 'La valeur mesurée ouvre l\'exercice qui l\'a produite.');
    }

    /**
     * À valeur égale, la ligne bascule sur le mesuré : le chiffre ne bouge pas,
     * mais il gagne sa date. C'est le cas courant chez qui tenait sa fiche à
     * jour à la main — sans ça, la mise à jour automatique ne se verrait jamais
     * chez lui.
     */
    public function testAnEqualLoggedSetStillDatesTheRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setSquat1rmKg(140.0);
        $this->em->flush();

        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');
        $this->log($user, $squat, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 1, 140.0]]);

        $row = $this->row($user, 'Squat');

        self::assertSame('140 kg', $row['value']);
        self::assertSame('1 rep · 12/08/26', $row['note']);
        self::assertSame($squat->getId(), $row['exerciseId']);
    }

    /** Une série plus légère ne dégrade pas le record déclaré : c'est un plancher. */
    public function testLighterLoggedSetLeavesTheDeclaredRecordAlone(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setSquat1rmKg(140.0);
        $this->em->flush();

        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');
        $this->log($user, $squat, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 5, 120.0]]);

        $row = $this->row($user, 'Squat');

        self::assertSame('140 kg', $row['value']);
        self::assertNull($row['note']);
        self::assertNull($row['exerciseId']);
    }

    /**
     * Aucune estimation : un 5 × 120 ne devient pas un 1RM de 140. Sans valeur
     * déclarée, le record est la charge réellement soulevée, et ses répétitions
     * sont écrites à côté — c'est ce que la note sert à dire.
     */
    public function testRecordIsTheWeightActuallyLiftedNotAnEstimate(): void
    {
        $user = $this->createUser('owner@example.com');
        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');
        $this->log($user, $squat, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 5, 120.0]]);

        $row = $this->row($user, 'Squat');

        self::assertSame('120 kg', $row['value']);
        self::assertSame('5 reps · 12/08/26', $row['note']);
    }

    /** Même périmètre que partout : un échauffement lourd n'est pas un record. */
    public function testWarmupNeverMakesARecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');
        $this->log($user, $squat, new \DateTimeImmutable('2026-08-12'), [
            [SetType::WARMUP, 3, 200.0],
            [SetType::NORMAL, 5, 100.0],
        ]);

        self::assertSame('100 kg', $this->row($user, 'Squat')['value']);
    }

    /**
     * Le soulevé de terre est la seule case à deux clés : conventionnel et sumo
     * sont le même lift, tirés autrement.
     */
    public function testSumoFeedsTheDeadliftRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createExercise('Soulevé de terre traditionnel', 'souleve-de-terre-traditionnel');
        $sumo = $this->createExercise('Soulevé de terre sumo', 'souleve-de-terre-sumo');
        $this->log($user, $sumo, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 3, 190.0]]);

        $row = $this->row($user, 'Soulevé de terre');

        self::assertSame('190 kg', $row['value']);
        self::assertSame($sumo->getId(), $row['exerciseId']);
    }

    /**
     * Un exercice perso ne porte pas de `refKey` et n'alimente donc aucune case :
     * un record de squat ne se lit pas sur « Squat maison ».
     */
    public function testPersonalExerciseWithoutRefKeyFeedsNothing(): void
    {
        $user = $this->createUser('owner@example.com');
        $mine = $this->createExercise('Squat maison', null, $user);
        $this->log($user, $mine, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 1, 200.0]]);

        self::assertNull($this->row($user, 'Squat')['value']);
    }

    /** Le réalisé d'un autre ne fait pas mon record, même sur un exercice global. */
    public function testRecordsAreScopedToTheirOwner(): void
    {
        $mine = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');
        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');

        $this->log($mine, $squat, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 5, 100.0]]);
        $this->log($other, $squat, new \DateTimeImmutable('2026-08-13'), [[SetType::NORMAL, 1, 200.0]]);

        self::assertSame('100 kg', $this->row($mine, 'Squat')['value']);
    }

    /**
     * Le total SBD suit les records EFFECTIFS. C'est ce qui empêche la fiche
     * d'afficher un total que ses propres lignes contredisent — et le score
     * DOTS, qui s'en déduit, d'ignorer un record fraîchement battu.
     */
    public function testSbdTotalFollowsTheRecordsActuallyShown(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setSquat1rmKg(140.0)->setBench1rmKg(100.0)->setDeadlift1rmKg(180.0);
        $this->em->flush();

        self::assertSame(420.0, $this->records->strengthFor($user)['sbdTotalKg']);

        $squat = $this->createExercise('Squat à la barre', 'barbell-squat');
        $this->log($user, $squat, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 1, 150.0]]);

        $strength = $this->records->strengthFor($user);

        self::assertSame(430.0, $strength['sbdTotalKg']);
        self::assertSame('430 kg', $this->pick($strength['rows'], 'Total SBD')['value']);
    }

    /** Un des trois lifts manquant : pas de total partiel, qui se comparerait à un total complet. */
    public function testSbdTotalIsNullWhileALiftIsMissing(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setSquat1rmKg(140.0)->setBench1rmKg(100.0);
        $this->em->flush();

        $strength = $this->records->strengthFor($user);

        self::assertNull($strength['sbdTotalKg']);
        self::assertNull($this->pick($strength['rows'], 'Total SBD')['value']);
    }

    /**
     * La suspension se compte en secondes tenues, pas en kilos : c'est la seule
     * case lue sur la durée, et elle se rend en mm:ss.
     */
    public function testDeadhangRecordIsTheLongestHold(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setDeadhangSeconds(60);
        $this->em->flush();

        $hang = $this->createExercise('Suspension à la barre fixe', 'suspension-a-la-barre-fixe');
        $this->log($user, $hang, new \DateTimeImmutable('2026-08-12'), [
            [SetType::NORMAL, null, null, 75],
            [SetType::NORMAL, null, null, 50],
        ]);

        $row = $this->row($user, 'Suspension à la barre');

        self::assertSame('1:15', $row['value']);
        // La durée EST la valeur : la note ne porte que la date.
        self::assertSame('12/08/26', $row['note']);
        self::assertSame($hang->getId(), $row['exerciseId']);
    }

    /** Une suspension plus courte que le temps déclaré ne le remplace pas. */
    public function testShorterHoldLeavesTheDeclaredDeadhangAlone(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setDeadhangSeconds(90);
        $this->em->flush();

        $hang = $this->createExercise('Suspension à la barre fixe', 'suspension-a-la-barre-fixe');
        $this->log($user, $hang, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, null, null, 45]]);

        $row = $this->row($user, 'Suspension à la barre');

        self::assertSame('1:30', $row['value']);
        self::assertNull($row['note']);
    }

    // --- Maximums au poids du corps ------------------------------------------

    /**
     * Un record qui se compte au lieu de se charger. La valeur est le nombre de
     * répétitions, la note ne porte que la date — le compte EST déjà l'effort.
     */
    public function testBodyweightRecordIsAMaximumOfReps(): void
    {
        $user = $this->createUser('owner@example.com');
        $pushup = $this->createExercise('Pompe', 'pompe');
        $this->log($user, $pushup, new \DateTimeImmutable('2026-08-12'), [
            [SetType::NORMAL, 40, null],
            [SetType::NORMAL, 25, null],
        ]);

        $row = $this->row($user, 'Pompes');

        self::assertSame('40 reps', $row['value']);
        self::assertSame('12/08/26', $row['note']);
        self::assertSame($pushup->getId(), $row['exerciseId']);
    }

    /**
     * Le point dur : une série LESTÉE n'entre pas dans un maximum au poids du
     * corps. Et il ne suffit pas de l'écarter à l'affichage — si le MAX était
     * cherché sur elle, la série à vide ne correspondrait plus à rien et le
     * record disparaîtrait au lieu de valoir 12.
     */
    public function testLoadedSetNeverMakesABodyweightRepRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $dips = $this->createExercise('Dips', 'dips');
        $this->log($user, $dips, new \DateTimeImmutable('2026-08-12'), [
            [SetType::NORMAL, 20, 15.0],
            [SetType::NORMAL, 12, null],
        ]);

        $row = $this->row($user, 'Dips');

        self::assertSame('12 reps', $row['value']);
        self::assertSame($dips->getId(), $row['exerciseId']);
    }

    /**
     * Une charge à zéro, c'est du poids du corps : c'est la forme que prend un
     * historique importé, et la lire autrement ferait sauter le record.
     */
    public function testAZeroLoadCountsAsBodyweight(): void
    {
        $user = $this->createUser('owner@example.com');
        $dips = $this->createExercise('Dips', 'dips');
        $this->log($user, $dips, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 17, 0.0]]);

        self::assertSame('17 reps', $this->row($user, 'Dips')['value']);
    }

    /**
     * La traction est le seul mouvement à porter deux cases : lestée on lit des
     * kilos, à vide on compte des répétitions. Les deux records coexistent sans
     * se marcher dessus.
     */
    public function testPullupsAndWeightedPullupsAreTwoDistinctRecords(): void
    {
        $user = $this->createUser('owner@example.com');
        $wide = $this->createExercise('Traction en pronation prise large', 'traction-en-pronation-prise-large');
        $weighted = $this->createExercise('Traction en pronation prise large lestée', 'traction-en-pronation-prise-large-lestee');

        $this->log($user, $wide, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 14, null]]);
        $this->log($user, $weighted, new \DateTimeImmutable('2026-08-14'), [[SetType::NORMAL, 4, 35.0]]);

        self::assertSame('14 reps', $this->row($user, 'Tractions')['value']);
        self::assertSame('35 kg', $this->row($user, 'Traction lestée')['value']);
    }

    /**
     * La prise large alimente la case « Tractions » au même titre que la prise
     * normale : c'est le même mouvement, tiré autrement. Sans ça, la case
     * resterait vide chez qui ne logue que la large.
     */
    public function testWideGripFeedsThePullupRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createExercise('Traction', 'traction');
        $wide = $this->createExercise('Traction en pronation prise large', 'traction-en-pronation-prise-large');
        $this->log($user, $wide, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 14, null]]);

        $row = $this->row($user, 'Tractions');

        self::assertSame('14 reps', $row['value']);
        self::assertSame($wide->getId(), $row['exerciseId']);
    }

    /** Le compte déclaré reste un plancher, comme les kilos. */
    public function testFewerRepsLeaveTheDeclaredMaximumAlone(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setMaxPushups(50);
        $this->em->flush();

        $pushup = $this->createExercise('Pompe', 'pompe');
        $this->log($user, $pushup, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 40, null]]);

        $row = $this->row($user, 'Pompes');

        self::assertSame('50 reps', $row['value']);
        self::assertNull($row['note']);
    }

    /**
     * Quatre requêtes, quel que soit le nombre de cases : la résolution des
     * clés, puis un record par métrique. La fiche est rendue sur la page
     * d'accueil, un N+1 s'y verrait tous les jours.
     */
    public function testStrengthStaysAtFourQueries(): void
    {
        $user = $this->createUser('owner@example.com');
        foreach ([
            'barbell-squat' => 'Squat à la barre',
            'developpe-couche' => 'Développé couché',
            'souleve-de-terre-traditionnel' => 'Soulevé de terre traditionnel',
            'souleve-de-terre-sumo' => 'Soulevé de terre sumo',
            'developpe-militaire' => 'Développé militaire',
            'traction-en-pronation-prise-large-lestee' => 'Traction lestée',
            'traction' => 'Traction',
            'traction-en-pronation-prise-large' => 'Traction en pronation prise large',
            'pompe' => 'Pompe',
            'dips' => 'Dips',
            'suspension-a-la-barre-fixe' => 'Suspension à la barre fixe',
        ] as $key => $name) {
            $exercise = $this->createExercise($name, $key);
            $this->log($user, $exercise, new \DateTimeImmutable('2026-08-12'), [[SetType::NORMAL, 5, 60.0, 40]]);
        }

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $this->records->strengthFor($user);

        self::assertCount(4, $holder->getData()['default'] ?? []);
    }

    /**
     * La ligne d'un record, par son libellé.
     *
     * @return array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}
     */
    private function row(User $user, string $label): array
    {
        return $this->pick($this->records->strengthFor($user)['rows'], $label);
    }

    /**
     * @param list<array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}> $rows
     *
     * @return array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}
     */
    private function pick(array $rows, string $label): array
    {
        foreach ($rows as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }

        self::fail(sprintf('Aucune ligne « %s » dans le bloc force.', $label));
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(string $name, ?string $refKey, ?User $owner = null): Exercise
    {
        $exercise = (new Exercise())
            ->setName($name)
            ->setRefKey($refKey)
            ->setOwner($owner)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    /**
     * Une séance datée qui porte le réalisé d'un seul exercice.
     *
     * @param list<array{0: SetType, 1: int|null, 2: float|null, 3?: int|null}> $sets
     */
    private function log(User $owner, Exercise $exercise, \DateTimeImmutable $date, array $sets): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date->format('d/m'))
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName($exercise->getName())
            ->setPosition(1)
            ->setSkipped(false);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2])
                    ->setDurationSeconds($set[3] ?? null)
            );
        }

        $scheduled->addLoggedExercise($logged);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
