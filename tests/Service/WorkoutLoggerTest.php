<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\SessionSheet;
use App\Service\WorkoutLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La boucle prévu vs réalisé, côté écriture.
 *
 * Ce que ces tests protègent, dans l'ordre d'importance :
 *
 * 1. LA PRESCRIPTION N'EST JAMAIS TOUCHÉE. C'est la règle qui rend la page
 *    d'exécution sûre : une séance peut être posée sur dix dates, pointer celle
 *    d'aujourd'hui ne doit rien changer aux neuf autres ni à la bibliothèque.
 * 2. Le pointage marche dans les DEUX modes de saisie (scalaire et détaillé) :
 *    c'est pour ça qu'on pointe (exercice + index) et non un PrescribedSet.
 * 3. L'idempotence : la file hors ligne rejoue des gestes, elle ne doit pas
 *    créer de doublons.
 */
final class WorkoutLoggerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkoutLogger $logger;
    private SessionSheet $sheet;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->logger = static::getContainer()->get(WorkoutLogger::class);
        $this->sheet = static::getContainer()->get(SessionSheet::class);

        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        $this->em->flush();
    }

    /**
     * Le test central : valider des séries n'écrit pas dans la séance. Sans cette
     * garantie, pointer une séance récurrente réécrirait sa prescription pour
     * toutes ses autres dates — c'est exactement le risque qui a fait choisir un
     * réalisé séparé plutôt qu'une mutation en place.
     */
    public function testLoggingNeverTouchesThePrescription(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 4, reps: 15, weightKg: 130.0);

        // On pointe deux séries, dont une avec une charge plus basse que prévu.
        $this->logger->log($scheduled, $prescribed, 1, 15, 130.0);
        $this->logger->log($scheduled, $prescribed, 2, 12, 120.0);
        $this->em->flush();
        $this->em->clear();

        $fresh = $this->em->getRepository(PrescribedExercise::class)->find($prescribed->getId());

        self::assertSame(4, $fresh->getSets());
        self::assertSame(15, $fresh->getReps());
        self::assertSame(130.0, $fresh->getWeightKg());
        self::assertCount(0, $fresh->getDetailedSets());
    }

    /**
     * Mode scalaire : aucune ligne PrescribedSet n'existe en base, la vue en
     * déroule pourtant N. Le pointage doit fonctionner sur ces lignes-là, sans
     * matérialiser le détail — c'est toute la raison du couple (exercice, index).
     */
    public function testScalarModeIsPointableWithoutMaterialisingDetailedSets(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 3, reps: 10, weightKg: 60.0);

        $sheet = $this->sheet->build($scheduled);
        $exercise = $this->sheet->findExercise($sheet, $prescribed->getId());

        self::assertTrue($exercise['perSet']);
        self::assertCount(3, $exercise['lines']);
        self::assertSame(3, $sheet['progress']['total']);
        self::assertSame(0, $sheet['progress']['done']);

        $this->logger->log($scheduled, $prescribed, 2, 10, 60.0);
        $this->em->flush();

        $progress = $this->sheet->progress($scheduled);
        self::assertSame(1, $progress['done']);
        self::assertFalse($progress['complete']);
        self::assertCount(0, $prescribed->getDetailedSets());
    }

    /**
     * Mode détaillé : l'échauffement est une ligne comme les autres du point de
     * vue du pointage (on le fait, donc on le coche), même s'il ne compte pas
     * dans le volume de travail ailleurs dans l'app.
     */
    public function testDetailedModeCountsEveryLineIncludingWarmup(): void
    {
        $scheduled = $this->session();
        $prescribed = $this->prescribedIn($scheduled->getWorkout(), PrescriptionType::SETS_REPS);
        $prescribed->setSets(2)->setReps(8)->setWeightKg(100.0);
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(10)->setWeightKg(40.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $this->em->flush();

        self::assertSame(3, $this->sheet->progress($scheduled)['total']);
    }

    /**
     * Un type d'effort sans séries (course, AMRAP…) reste pointable : une ligne
     * unique qui vaut « l'exercice est fait ». Sans ça, une séance de course
     * n'aurait aucun geste possible sur cette page.
     */
    public function testExerciseWithoutSetsIsPointableAsASingleLine(): void
    {
        $scheduled = $this->session();
        $prescribed = $this->prescribedIn($scheduled->getWorkout(), PrescriptionType::DISTANCE_PACE, ActivityType::RUNNING);
        $prescribed->setDistanceMeters(5000)->setPaceSecondsPerKm(300);
        $this->em->flush();

        $exercise = $this->sheet->findExercise($this->sheet->build($scheduled), $prescribed->getId());

        self::assertFalse($exercise['perSet']);
        self::assertCount(1, $exercise['lines']);
        self::assertSame(1, $exercise['lines'][0]['index']);
    }

    /**
     * La file hors ligne rejoue des gestes après une reconnexion : rejouer la
     * même validation doit mettre à jour la ligne, pas en créer une seconde (la
     * contrainte d'unicité en base lèverait sinon une erreur).
     */
    public function testLoggingTheSameLineTwiceUpdatesInsteadOfDuplicating(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 3, reps: 10, weightKg: 60.0);

        $this->logger->log($scheduled, $prescribed, 1, 10, 60.0);
        $this->em->flush();
        $this->logger->log($scheduled, $prescribed, 1, 8, 55.0);
        $this->em->flush();

        $logs = $this->em->getRepository(LoggedSet::class)->findBy(['scheduledWorkout' => $scheduled]);

        self::assertCount(1, $logs);
        self::assertSame(8, $logs[0]->getReps());
        self::assertSame(55.0, $logs[0]->getWeightKg());
    }

    public function testUnlogRemovesTheLineAndIsSafeWhenAbsent(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 3, reps: 10, weightKg: 60.0);

        $this->logger->log($scheduled, $prescribed, 1, 10, 60.0);
        $this->em->flush();
        $this->logger->unlog($scheduled, $prescribed, 1);
        $this->em->flush();

        self::assertCount(0, $this->em->getRepository(LoggedSet::class)->findBy(['scheduledWorkout' => $scheduled]));

        // Rejeu d'une dévalidation déjà appliquée : sans effet, pas d'erreur.
        $this->logger->unlog($scheduled, $prescribed, 1);
        $this->em->flush();

        self::assertSame(0, $this->sheet->progress($scheduled)['done']);
    }

    /**
     * « Tout valider comme prévu » remplit les manques avec les valeurs
     * prescrites, et ne réécrit PAS une série déjà pointée : une charge corrigée
     * à la main pendant la séance est de l'information, pas du bruit.
     */
    public function testCompleteAllFillsGapsWithoutOverwritingCorrectedValues(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 4, reps: 15, weightKg: 130.0);

        $this->logger->log($scheduled, $prescribed, 2, 12, 120.0);
        $this->em->flush();

        $added = $this->logger->completeAll($scheduled);
        $this->em->flush();

        self::assertSame(3, $added);

        $progress = $this->sheet->progress($scheduled);
        self::assertSame(4, $progress['done']);
        self::assertTrue($progress['complete']);

        $corrected = $this->em->getRepository(LoggedSet::class)->findOneBy([
            'scheduledWorkout' => $scheduled,
            'setIndex' => 2,
        ]);
        self::assertSame(12, $corrected->getReps());
        self::assertSame(120.0, $corrected->getWeightKg());
    }

    /**
     * Une série faite en plus du prévu est acceptée telle quelle, et ne fait pas
     * dépasser la progression : le total reste celui du prescrit, sinon un
     * exercice supplémentaire ferait tomber la séance sous les 100 % alors qu'on
     * en a fait davantage.
     */
    public function testExtraSetIsAcceptedAndDoesNotInflateTheTotal(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 2, reps: 10, weightKg: 60.0);

        $this->logger->log($scheduled, $prescribed, 1, 10, 60.0);
        $this->logger->log($scheduled, $prescribed, 2, 10, 60.0);
        $this->logger->log($scheduled, $prescribed, 3, 8, 60.0);
        $this->em->flush();

        $sheet = $this->sheet->build($scheduled);
        $exercise = $this->sheet->findExercise($sheet, $prescribed->getId());

        self::assertCount(3, $exercise['lines']);
        self::assertTrue($exercise['lines'][2]['extra']);
        self::assertSame(2, $sheet['progress']['total']);
        self::assertTrue($sheet['progress']['complete']);
        self::assertSame(100, $sheet['progress']['percent']);
    }

    /**
     * Un exercice ne peut être pointé que depuis SA séance datée : l'id transite
     * par le formulaire, il ne doit pas ouvrir le réalisé d'une autre séance.
     */
    public function testLoggingAnExerciseFromAnotherWorkoutIsRejected(): void
    {
        [$scheduled] = $this->scalarSession(sets: 2, reps: 10, weightKg: 60.0);
        [, $foreign] = $this->scalarSession(sets: 2, reps: 10, weightKg: 60.0);

        $this->expectException(\InvalidArgumentException::class);

        $this->logger->log($scheduled, $foreign, 1, 10, 60.0);
    }

    /**
     * Retirer la séance datée efface son réalisé : un log sans sa date n'est plus
     * interprétable.
     */
    public function testDeletingTheScheduledWorkoutRemovesItsLog(): void
    {
        [$scheduled, $prescribed] = $this->scalarSession(sets: 2, reps: 10, weightKg: 60.0);

        $this->logger->log($scheduled, $prescribed, 1, 10, 60.0);
        $this->em->flush();

        $this->em->remove($scheduled);
        $this->em->flush();

        self::assertCount(0, $this->em->getRepository(LoggedSet::class)->findAll());
    }

    // ---- Fixtures -----------------------------------------------------------

    /**
     * Séance datée d'un seul exercice de force en mode scalaire.
     *
     * @return array{ScheduledWorkout, PrescribedExercise}
     */
    private function scalarSession(int $sets, int $reps, float $weightKg): array
    {
        $scheduled = $this->session();
        $prescribed = $this->prescribedIn($scheduled->getWorkout(), PrescriptionType::SETS_REPS);
        $prescribed->setSets($sets)->setReps($reps)->setWeightKg($weightKg);
        $this->em->flush();

        return [$scheduled, $prescribed];
    }

    private function session(): ScheduledWorkout
    {
        $user = (new User())->setEmail(uniqid('athlete', true).'@example.com')->setPassword('x');
        $this->em->persist($user);

        $workout = (new Workout())->setOwner($user)->setTitle('Push A')->setSlug(uniqid('push-a', true));
        $this->em->persist($workout);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-27'))
            ->setStatus(ScheduledStatus::PLANNED);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function prescribedIn(
        Workout $workout,
        PrescriptionType $type,
        ActivityType $activity = ActivityType::GYM,
    ): PrescribedExercise {
        $exercise = (new Exercise())->setName(uniqid('Exercice ', true))->setActivity($activity);
        $this->em->persist($exercise);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);

        $prescribed = (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPrescriptionType($type)
            ->setPosition(0);
        $block->addPrescribedExercise($prescribed);
        $this->em->persist($prescribed);

        return $prescribed;
    }
}
