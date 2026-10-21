<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'accueil (`/`) : « où j'en suis ». Il est séparé du profil (`/profile`),
 * qu'il ne remplace pas : il n'en reprend ni les records ni la fiche.
 */
final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : tout ce qui référence user passe avant les users.
        foreach ([Coaching::class, Goal::class, ScheduledWorkout::class, PlanTemplate::class, Workout::class, Exercise::class, User::class] as $class) {
            foreach ($this->em->getRepository($class)->findAll() as $entity) {
                $this->em->remove($entity);
            }
            $this->em->flush();
        }
    }

    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testTheNextGoalLeadsAndTheOneAfterFollows(): void
    {
        $user = $this->createUser('home@example.com');
        foreach (['Trail des Cimes' => '+38 days', 'Total SBD 470 kg' => '+71 days', 'Marathon' => '+120 days'] as $title => $when) {
            $this->em->persist((new Goal())->setOwner($user)->setTitle($title)->setTargetDate(new \DateTimeImmutable($when)));
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-home__goaltitle', 'Trail des Cimes');
        self::assertSelectorTextContains('.kd-home__goalcount', 'J-38');
        self::assertSelectorTextContains('.kd-home__goalnext', 'Total SBD 470 kg');
        self::assertStringNotContainsString('Marathon', $crawler->filter('.kd-home__goal')->text(), 'Deux objectifs, pas trois.');
        self::assertCount(0, $crawler->filter('.kd-deflist--profile'), 'La fiche est sur /profile, pas sur l\'accueil.');
    }

    public function testTheMonthCardsReadTheCurrentMonth(): void
    {
        $user = $this->createUser('month@example.com');
        $today = new \DateTimeImmutable('today');
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Faite')
            ->setScheduledDate($today)->setStatus(ScheduledStatus::DONE));
        // Le mois dernier ne compte pas.
        $this->em->persist((new ScheduledWorkout())->setOwner($user)->setTitle('Ancienne')
            ->setScheduledDate($today->modify('first day of last month'))->setStatus(ScheduledStatus::MISSED));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-observance__pct', '100%');
        self::assertGreaterThan(
            0,
            $crawler->filter('a.kd-home__card[href="/profile/stats?range='.$today->format('Y-m').'"]')->count(),
            'Les cartes du mois ouvrent les statistiques du même mois.',
        );
    }

    public function testAPendingCoachingRequestAsksForAnAnswer(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->em->persist((new Coaching())->setCoach($coach)->setAthlete($athlete)
            ->setRequestedBy($coach)->setStatus(CoachingStatus::PENDING));
        $this->em->flush();

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.kd-home__coach', 'coach@example.com propose de t\'entraîner');
        self::assertCount(2, $crawler->filter('.kd-home__coach form'));

        // Celui qui a demandé n'a rien à répondre.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.kd-home__coach'));
    }

    public function testTheShortcutsLeadToTheReadingPages(): void
    {
        $this->client->loginUser($this->createUser('links@example.com'));
        $crawler = $this->client->request('GET', '/');

        foreach (['/profile', '/profile/log', '/profile/history', '/profile/stats', '/exercise', '/goal'] as $href) {
            self::assertCount(1, $crawler->filter('.kd-home__index a[href="'.$href.'"]'), $href);
        }
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
