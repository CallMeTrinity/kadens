<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Goal;
use App\Entity\GoalBlock;
use App\Entity\ImportedActivity;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Enum\CoachingStatus;
use App\Enum\GoalBlockType;
use App\Enum\ScheduledStatus;
use App\Service\GoalPhotoStorage;
use App\Tests\BuildsJpeg;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le récit d'un objectif (#42) : l'éditeur de blocs, les photos et la
 * publication.
 *
 * Ce que les tests tiennent :
 * - l'ordre du récit et ses gardes de saisie ;
 * - une photo ne se lit qu'avec le droit de lire l'objectif, ou par la page
 *   publique tant qu'elle est publiée — jamais autrement ;
 * - on ne raconte que ses propres sorties, et seulement les séances FAITES ;
 * - le coach co-écrit le récit mais ne publie pas : c'est le contenu de
 *   l'athlète qui sortirait ;
 * - la page publique ne montre ni les plans ni rien de la préparation.
 */
final class GoalStoryControllerTest extends WebTestCase
{
    use BuildsJpeg;
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testOwnerWritesTextBlocksAndReordersThem(): void
    {
        $user = $this->createUser('owner@example.com');
        $goal = $this->createGoal($user, 'Trail des Templiers');
        $this->client->loginUser($user);

        $this->addBlock($goal, ['type' => 'text', 'body' => 'Premier paragraphe']);
        $this->addBlock($goal, ['type' => 'text', 'body' => 'Second paragraphe']);

        $blocks = $this->blocksOf($goal);
        self::assertSame(['Premier paragraphe', 'Second paragraphe'], array_map(static fn (GoalBlock $b) => $b->getBody(), $blocks));

        $this->client->request('POST', sprintf('/goal/%d/story/%d/move', $goal->getId(), $blocks[1]->getId()), [
            '_token' => $this->storyToken($goal), 'direction' => 'up',
        ]);

        self::assertSame(['Second paragraphe', 'Premier paragraphe'], array_map(static fn (GoalBlock $b) => $b->getBody(), $this->blocksOf($goal)));
        self::assertSame([0, 1], array_map(static fn (GoalBlock $b) => $b->getPosition(), $this->blocksOf($goal)));
    }

    public function testAnEmptyTextBlockIsRefused(): void
    {
        $user = $this->createUser('owner@example.com');
        $goal = $this->createGoal($user, 'Marathon');
        $this->client->loginUser($user);

        $this->addBlock($goal, ['type' => 'text', 'body' => "  \n "]);

        self::assertResponseRedirects('/goal/'.$goal->getId().'/story');
        self::assertSame([], $this->blocksOf($goal));
    }

    public function testStoryMutationsRequireACsrfToken(): void
    {
        $user = $this->createUser('owner@example.com');
        $goal = $this->createGoal($user, 'Marathon');
        $this->client->loginUser($user);

        $this->client->request('POST', '/goal/'.$goal->getId().'/story/add', ['type' => 'text', 'body' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->blocksOf($goal));
    }

    public function testAStrangerCannotOpenTheEditor(): void
    {
        $goal = $this->createGoal($this->createUser('owner@example.com'), 'Objectif privé');

        $this->client->loginUser($this->createUser('intruder@example.com'));
        $this->client->request('GET', '/goal/'.$goal->getId().'/story');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Rangée sans EXIF, servie au propriétaire, refusée à un tiers, et effacée
     * du disque avec son bloc.
     */
    public function testPhotoLifecycle(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $goal = $this->createGoal($owner, 'Ultra');
        $this->client->loginUser($owner);

        $this->addBlock($goal, ['type' => 'photo', 'body' => 'Au départ'], self::jpeg(800, 600));

        [$block] = $this->blocksOf($goal);
        self::assertSame(GoalBlockType::PHOTO, $block->getType());
        self::assertSame(800, $block->getPhotoWidth());
        $storage = static::getContainer()->get(GoalPhotoStorage::class);
        $path = $storage->path((string) $block->getPhotoFile());
        self::assertFileExists($path);
        self::assertStringNotContainsString(self::EXIF_MARKER, (string) file_get_contents($path));

        $photoUrl = sprintf('/goal/%d/photo/%d', $goal->getId(), $block->getId());
        $this->client->request('GET', $photoUrl);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        $this->client->loginUser($intruder);
        $this->client->request('GET', $photoUrl);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($owner);
        $this->client->request('POST', sprintf('/goal/%d/story/%d/delete', $goal->getId(), $block->getId()), [
            '_token' => $this->storyToken($goal),
        ]);
        self::assertSame([], $this->blocksOf($goal));
        self::assertFileDoesNotExist($path);
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $user = $this->createUser('owner@example.com');
        $goal = $this->createGoal($user, 'Ultra');
        $this->client->loginUser($user);

        $this->addBlock($goal, ['type' => 'photo'], '<?php echo "bonjour";');

        self::assertResponseRedirects('/goal/'.$goal->getId().'/story');
        self::assertSame([], $this->blocksOf($goal));
    }

    /** Une sortie d'un autre compte rend 404, même par identifiant forgé. */
    public function testOnlyTheOwnersActivitiesCanBeTold(): void
    {
        $owner = $this->createUser('owner@example.com');
        $goal = $this->createGoal($owner, 'Semi');
        $mine = $this->createActivity($owner, 'mine');
        $theirs = $this->createActivity($this->createUser('other@example.com'), 'theirs');
        $this->client->loginUser($owner);

        $this->addBlock($goal, ['type' => 'activity', 'importedActivity' => $theirs->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->addBlock($goal, ['type' => 'activity', 'importedActivity' => $mine->getId(), 'body' => 'Dernière sortie longue']);
        [$block] = $this->blocksOf($goal);
        self::assertSame($mine->getId(), $block->getImportedActivity()?->getId());

        // La même sortie ne se raconte pas deux fois.
        $this->addBlock($goal, ['type' => 'activity', 'importedActivity' => $mine->getId()]);
        self::assertCount(1, $this->blocksOf($goal));
    }

    /** Le récit dit ce qui a eu lieu : une séance seulement prévue n'y entre pas. */
    public function testOnlyDoneSessionsCanBeTold(): void
    {
        $owner = $this->createUser('owner@example.com');
        $goal = $this->createGoal($owner, 'Semi');
        $planned = $this->createSession($owner, ScheduledStatus::PLANNED);
        $done = $this->createSession($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);

        $this->addBlock($goal, ['type' => 'session', 'scheduledWorkout' => $planned->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->addBlock($goal, ['type' => 'session', 'scheduledWorkout' => $done->getId()]);
        [$block] = $this->blocksOf($goal);
        self::assertSame($done->getId(), $block->getScheduledWorkout()?->getId());
    }

    /** Retirer la séance du calendrier n'efface pas ce qu'on a écrit autour. */
    public function testRemovingTheSourceKeepsTheComment(): void
    {
        $owner = $this->createUser('owner@example.com');
        $goal = $this->createGoal($owner, 'Semi');
        $done = $this->createSession($owner, ScheduledStatus::DONE);
        $this->client->loginUser($owner);

        $this->addBlock($goal, ['type' => 'session', 'scheduledWorkout' => $done->getId(), 'body' => 'Les jambes répondaient']);

        $this->em->clear();
        $this->em->remove($this->mustFind(ScheduledWorkout::class, $done->getId()));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Les jambes répondaient', $crawler->filter('#recit')->text());
    }

    public function testABlockOfAnotherGoalIsNotFound(): void
    {
        $owner = $this->createUser('owner@example.com');
        $goal = $this->createGoal($owner, 'A');
        $other = $this->createGoal($owner, 'B');
        $this->client->loginUser($owner);
        $this->addBlock($other, ['type' => 'text', 'body' => 'Bloc de B']);
        [$block] = $this->blocksOf($other);

        $this->client->request('POST', sprintf('/goal/%d/story/%d/delete', $goal->getId(), $block->getId()), [
            '_token' => $this->storyToken($goal),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->blocksOf($other));
    }

    /** Le coach co-écrit, mais publier exposerait le contenu de l'athlète : 403. */
    public function testTheCoachWritesButDoesNotPublish(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->acceptCoaching($coach, $athlete);
        $goal = $this->createGoal($athlete, 'Objectif de l\'athlète');

        $this->client->loginUser($coach);
        $this->addBlock($goal, ['type' => 'text', 'body' => 'Mot du coach']);
        self::assertCount(1, $this->blocksOf($goal));

        $crawler = $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertCount(0, $crawler->filter('form[action="/goal/'.$goal->getId().'/publish"]'));

        $this->client->request('POST', '/goal/'.$goal->getId().'/publish', ['action' => 'publish', '_token' => 'peu importe']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->mustFind(Goal::class, $goal->getId())->isPublished());
    }

    /**
     * Publier ouvre la page et ses photos sans compte ; dépublier les ferme en
     * 404 ; republier redonne le même lien.
     */
    public function testPublishAndUnpublish(): void
    {
        $owner = $this->createUser('owner@example.com');
        $goal = $this->createGoal($owner, 'Trail des Templiers');
        $this->client->loginUser($owner);
        $this->addBlock($goal, ['type' => 'text', 'body' => 'Le récit de la course']);
        $this->addBlock($goal, ['type' => 'photo'], self::jpeg());
        $photo = $this->blocksOf($goal)[1];

        $this->publish($goal, 'publish');
        $slug = (string) $this->mustFind(Goal::class, $goal->getId())->getSlug();
        self::assertSame('trail-des-templiers', $slug);

        // Anonyme : on oublie la session, comme un lecteur qui reçoit le lien.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/s/goal/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Le récit de la course', $crawler->text());
        $this->client->request('GET', sprintf('/s/goal/%s/photo/%d', $slug, $photo->getId()));
        self::assertResponseIsSuccessful();

        $this->client->loginUser($owner);
        $this->publish($goal, 'unpublish');
        $this->client->request('GET', '/s/goal/'.$slug);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/s/goal/%s/photo/%d', $slug, $photo->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->publish($goal, 'publish');
        self::assertSame($slug, $this->mustFind(Goal::class, $goal->getId())->getSlug());
    }

    /** Ce qui se raconte, pas la préparation : un plan rattaché ne sort jamais. */
    public function testThePublicPageNeverShowsPlans(): void
    {
        $owner = $this->createUser('owner@example.com');
        $goal = $this->createGoal($owner, 'Marathon');
        $plan = (new PlanTemplate())->setOwner($owner)->setTitle('Plan secret')->setDurationWeeks(4)->setSlug('plan-secret');
        $plan->addGoal($goal);
        $this->em->persist($plan);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->publish($goal, 'publish');

        $crawler = $this->client->request('GET', '/s/goal/'.$this->mustFind(Goal::class, $goal->getId())->getSlug());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Plan secret', $crawler->text());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function addBlock(Goal $goal, array $fields, ?string $photoBytes = null): void
    {
        $files = [];
        if (null !== $photoBytes) {
            $path = tempnam(sys_get_temp_dir(), 'kd-upload');
            file_put_contents($path, $photoBytes);
            $files['photo'] = new UploadedFile($path, 'photo.jpg', null, null, true);
        }

        $this->client->request('POST', '/goal/'.$goal->getId().'/story/add', $fields + ['_token' => $this->storyToken($goal)], $files);
    }

    private function publish(Goal $goal, string $action): void
    {
        $crawler = $this->client->request('GET', '/goal/'.$goal->getId());
        $token = $crawler->filter('form[action="/goal/'.$goal->getId().'/publish"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/goal/'.$goal->getId().'/publish', ['_token' => $token, 'action' => $action]);
        self::assertResponseRedirects('/goal/'.$goal->getId());

        // La requête a écrit par le gestionnaire d'un noyau redémarré : celui du
        // test garde sinon l'objectif d'avant (slug nul) dans sa carte d'identité.
        $this->em->clear();
    }

    /** Le jeton se lit sur l'éditeur, comme le ferait un navigateur. */
    private function storyToken(Goal $goal): string
    {
        $crawler = $this->client->request('GET', '/goal/'.$goal->getId().'/story');

        return (string) $crawler->filter('form[action="/goal/'.$goal->getId().'/story/add"] input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return list<GoalBlock>
     */
    private function blocksOf(Goal $goal): array
    {
        $this->em->clear();

        return $this->mustFind(Goal::class, $goal->getId())->getOrderedBlocks();
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createGoal(User $owner, string $title): Goal
    {
        $goal = (new Goal())
            ->setOwner($owner)
            ->setTitle($title)
            ->setTargetDate(new \DateTimeImmutable('+30 days'));
        $this->em->persist($goal);
        $this->em->flush();

        return $goal;
    }

    private function createActivity(User $owner, string $externalId): ImportedActivity
    {
        $day = new \DateTimeImmutable('-3 days');
        $activity = (new ImportedActivity($owner, ActivitySource::INTERVALS, $externalId, 'Run', $day->setTime(7, 0), $day->setTime(0, 0)))
            ->setActivity(ActivityType::RUNNING)
            ->setName('Sortie longue')
            ->setDistanceMeters(25000)
            ->setMovingSeconds(9000);
        $this->em->persist($activity);
        $this->em->flush();

        return $activity;
    }

    private function createSession(User $owner, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du jour')
            ->setScheduledDate(new \DateTimeImmutable('-2 days'))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function acceptCoaching(User $coach, User $athlete): void
    {
        $this->em->persist(
            (new Coaching())
                ->setCoach($coach)
                ->setAthlete($athlete)
                ->setStatus(CoachingStatus::ACCEPTED)
                ->setRequestedBy($coach)
        );
        $this->em->flush();
    }
}
