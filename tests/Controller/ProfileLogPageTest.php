<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La page `/profile/log` — le journal du réalisé.
 *
 * Elle existe parce que ni les statistiques (qui agrègent) ni l'historique (qui
 * situe, sans chiffres) ne permettaient de PARCOURIR les séances une à une avec
 * ce que chacune a pesé. Le coach n'en voyait que les dix dernières sur la fiche
 * de son athlète, sans porte de sortie.
 *
 * Ce qui se joue ici :
 *
 * 1. **Elle ne montre que du réalisé.** Une séance cochée « faite » sans une
 *    seule série consignée n'a rien à dire ici — la combler avec du prescrit
 *    ferait passer une intention pour un fait.
 * 2. **Elle est scopée sur son lecteur.** Le journal d'un autre ne s'y invite
 *    jamais.
 * 3. **Sa fenêtre est celle des statistiques**, et le sélecteur reste sur la
 *    page : il change de fenêtre, pas de page.
 */
final class ProfileLogPageTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/profile/log');

        self::assertResponseRedirects('/login');
    }

    /** Un compte neuf : pas de journal, un état vide dessiné, aucune erreur. */
    public function testRendersOnAnEmptyAccount(): void
    {
        $this->client->loginUser($this->createUser('neuf@example.com'));
        $this->client->request('GET', '/profile/log');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.kd-empty');
    }

    /**
     * Une séance consignée porte sa ligne, ses chiffres et son mois. Les séries
     * comptées sont celles de travail : l'échauffement n'en est pas.
     */
    public function testALoggedSessionCarriesItsFiguresAndItsMonth(): void
    {
        $user = $this->createUser('journal@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $session = $this->logSession($user, $exercise, '2026-03-08', [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/log?range=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/schedule/'.$session->getId().'?from=log"]');
        $line = $crawler->filter('.kd-logline')->text();
        self::assertStringContainsString('2 séries', $line);
        self::assertStringContainsString('1 000 kg', $line);
        // Le mois est nommé une fois, en tête de son groupe.
        self::assertStringContainsString('mars 2026', $crawler->filter('.kd-loggroup__head')->text());
    }

    /**
     * Une séance simplement cochée « faite » n'a pas de réalisé : elle compte en
     * assiduité (c'est l'affaire des statistiques), pas ici.
     */
    public function testASessionTickedDoneWithoutALogIsNotListed(): void
    {
        $user = $this->createUser('journal@example.com');

        $bare = (new ScheduledWorkout())
            ->setOwner($user)
            ->setTitle('Sortie longue')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-09'))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($bare);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile/log?range=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="/schedule/'.$bare->getId().'"]');
    }

    /** Le journal d'un autre ne s'invite pas dans le mien. */
    public function testItOnlyShowsTheReadersOwnSessions(): void
    {
        $me = $this->createUser('moi@example.com');
        $other = $this->createUser('autre@example.com');
        $exercise = $this->createExercise(null, 'Squat');

        $mine = $this->logSession($me, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 100.0]]);
        $theirs = $this->logSession($other, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 200.0]]);

        $this->client->loginUser($me);
        $this->client->request('GET', '/profile/log?range=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href^="/schedule/'.$mine->getId().'"]');
        self::assertSelectorNotExists('a[href^="/schedule/'.$theirs->getId().'"]');
    }

    /**
     * La fenêtre borne la liste sans rien effacer : hors des quatre semaines par
     * défaut, la séance disparaît de la page mais le total continue de la
     * compter — c'est ce qui dit qu'il faut élargir plutôt que de conclure qu'il
     * n'y a rien.
     */
    public function testTheWindowBoundsTheListAndTheSelectorStaysOnThePage(): void
    {
        $user = $this->createUser('journal@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $old = $this->logSession($user, $exercise, '2024-01-15', [[SetType::NORMAL, 5, 80.0]]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/log');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="/schedule/'.$old->getId().'"]');
        self::assertStringContainsString('1 séance consignée au total', $crawler->filter('.kd-empty')->text());
        // Le sélecteur vise le journal, pas les statistiques.
        self::assertSelectorExists('a[href="/profile/log?range=all"]');

        $this->client->request('GET', '/profile/log?range=all');
        self::assertSelectorExists('a[href^="/schedule/'.$old->getId().'"]');
    }

    /**
     * Le jeton posé sur chaque ligne ramène AU JOURNAL. Sans lui, la séance
     * datée retombait sur le calendrier, qui n'est pas d'où l'on venait.
     */
    public function testASessionOpenedFromTheJournalComesBackToIt(): void
    {
        $user = $this->createUser('journal@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $session = $this->logSession($user, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 100.0]]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$session->getId().'?from=log');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/profile/log"]')->count());
    }

    /** Une page de lecture doit être atteignable depuis les deux autres. */
    public function testItIsReachableFromTheProfileAndTheOtherReadingPages(): void
    {
        $this->client->loginUser($this->createUser('journal@example.com'));

        foreach (['/', '/profile/stats', '/profile/history'] as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertGreaterThan(
                0,
                $crawler->filter('a[href="/profile/log"]')->count(),
                \sprintf('Le journal n\'est pas atteignable depuis %s.', $page),
            );
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<array{0: SetType, 1: int, 2: float}> $sets */
    private function logSession(User $owner, Exercise $exercise, string $date, array $sets): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition(1);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2]),
            );
        }

        $scheduled->addLoggedExercise($logged);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function createExercise(?User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM)
            ->setTargetAreas([TargetArea::QUADRICEPS]);
        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
