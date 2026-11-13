<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\ExerciseLanguage;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Tests\PurgesDatabase;
use App\Tests\ReadsSpreadsheets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le câblage de l'export : droits, réponse téléchargeable, et ce que seul un
 * vrai lecteur connecté révèle (sa langue d'exercices, son calendrier à lui,
 * le ressenti lu en base). Le contenu fin du classeur se teste dans
 * `ExcelExporterTest`.
 */
final class ExportControllerTest extends WebTestCase
{
    use PurgesDatabase;
    use ReadsSpreadsheets;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testTheOwnerDownloadsAWorkbookInTheirExerciseLanguage(): void
    {
        $owner = $this->createUser('owner@example.com')->setExerciseLanguage(ExerciseLanguage::EN);
        $workout = $this->createWorkout($owner, 'Haut du corps');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/export/workout/'.$workout->getId());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=kadens-seance-'.$workout->getSlug().'.xlsx');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $text = $this->downloadedText();
        self::assertStringContainsString('Haut du corps', $text);
        self::assertStringContainsString('Bench press', $text);
        self::assertStringNotContainsString('Développé couché', $text);
    }

    public function testAStrangerCannotExportSomeoneElsesContent(): void
    {
        $owner = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($owner, 'Haut du corps');
        $template = $this->createPlan($owner, $workout);
        $this->em->flush();

        $this->client->loginUser($this->createUser('stranger@example.com'));

        $this->client->request('GET', '/export/workout/'.$workout->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/export/plan-template/'.$template->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePlanExportOpensOnItsGrid(): void
    {
        $owner = $this->createUser('owner@example.com');
        $template = $this->createPlan($owner, $this->createWorkout($owner, 'Haut du corps'));
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/export/plan-template/'.$template->getId());

        self::assertResponseIsSuccessful();
        $book = $this->readSpreadsheet((string) $this->client->getInternalResponse()->getContent());
        self::assertSame(['Plan', 'Séances'], $book->getSheetNames());
        self::assertStringContainsString('Haut du corps', $this->spreadsheetText($book->getSheetByNameOrThrow('Plan')));
    }

    /**
     * Le mois exporté est celui du lecteur, séances libres comprises, avec le
     * ressenti relu en base (`ratingsFor`) sur la séance faite.
     */
    public function testTheScheduleExportListsTheReadersMonthWithRatings(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');
        $workout = $this->createWorkout($owner, 'Haut du corps');

        $this->em->persist((new ScheduledWorkout())
            ->setOwner($owner)->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-03-10'))
            ->setStatus(ScheduledStatus::DONE)
            ->setFeeling(4)->setSessionRpe(7));
        $this->em->persist((new ScheduledWorkout())
            ->setOwner($owner)->setTitle('Séance improvisée')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-15'))
            ->setStatus(ScheduledStatus::PLANNED));
        $this->em->persist((new ScheduledWorkout())
            ->setOwner($other)->setTitle('Pas la mienne')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-12'))
            ->setStatus(ScheduledStatus::PLANNED));
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/export/schedule/2026/3');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=kadens-planning-2026-03.xlsx');

        $book = $this->readSpreadsheet((string) $this->client->getInternalResponse()->getContent());
        $list = $this->spreadsheetText($book->getSheetByNameOrThrow('Planning'));

        self::assertStringContainsString('Mars 2026', $list);
        self::assertStringContainsString('Haut du corps', $list);
        self::assertStringContainsString('En forme', $list);
        self::assertStringContainsString('Séance improvisée', $list);
        self::assertStringNotContainsString('Pas la mienne', $list);
    }

    public function testAnInvalidMonthIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('owner@example.com'));
        $this->client->request('GET', '/export/schedule/2026/13');

        self::assertResponseStatusCodeSame(404);
    }

    // --------------------------------------------------------- Fixtures

    private function downloadedText(): string
    {
        return $this->spreadsheetText($this->readSpreadsheet((string) $this->client->getInternalResponse()->getContent()));
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createWorkout(User $owner, string $title): Workout
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName('Développé couché')
            ->setNameEn('Bench press')
            ->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(4)));
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise((new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(3)->setReps(8)->setWeightKg(80.0));
        $workout->addBlock($block);
        $this->em->persist($workout);
        $this->em->persist($block);

        return $workout;
    }

    private function createPlan(User $owner, Workout $workout): PlanTemplate
    {
        $template = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle('Prépa')
            ->setDurationWeeks(2)
            ->setSlug('prepa-'.bin2hex(random_bytes(4)));
        $item = (new PlanItem())->setWorkout($workout)->setWeekNumber(1)->setDayOfWeek(2);
        $template->addPlanItem($item);
        $this->em->persist($template);
        $this->em->persist($item);

        return $template;
    }
}
