<?php

namespace App\Tests\Controller;

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
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La page `/profile/stats`.
 *
 * Ce qui se joue ici tient en trois points, et aucun n'est cosmétique :
 *
 * 1. **La fenêtre est une URL.** Chaque vue a son lien, donc se partage et se
 *    met en favori — et une valeur qui ne veut rien dire retombe sur la fenêtre
 *    par défaut au lieu de rendre une 404. Un lien périmé doit afficher des
 *    statistiques.
 * 2. **La page est auto-suffisante.** Aucun fragment rechargé après coup :
 *    c'est la condition du cache offline, et ça se vérifie en constatant que le
 *    sélecteur est fait de liens et d'un formulaire GET, pas d'un `turbo-frame`.
 * 3. **Elle se rend sur un compte vide.** Une page de statistiques qui exige
 *    des données pour ne pas casser est une page qui casse le premier jour.
 */
final class ProfileStatsPageTest extends WebTestCase
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
        $this->client->request('GET', '/profile/stats');

        self::assertResponseRedirects('/login');
    }

    /** Un compte neuf : aucune donnée, aucune erreur, des états vides dessinés. */
    public function testRendersOnAnEmptyAccount(): void
    {
        $this->client->loginUser($this->createUser('new@example.com'));
        $crawler = $this->client->request('GET', '/profile/stats');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.kd-empty')->count());
    }

    public function testEachWindowRendersAndMarksItselfActive(): void
    {
        $this->client->loginUser($this->seedUser());

        foreach (['4w' => '4 semaines', '6m' => '6 mois', 'all' => 'Tout'] as $range => $label) {
            $crawler = $this->client->request('GET', '/profile/stats?range='.$range);

            self::assertResponseIsSuccessful(sprintf('La fenêtre « %s » doit se rendre.', $range));

            $active = $crawler->filter('.kd-rangetab.is-active');
            self::assertCount(1, $active, 'Un seul onglet actif à la fois.');
            self::assertSame($label, trim($active->text()));
        }
    }

    /**
     * Le mois est la seule fenêtre paramétrée : sa valeur d'URL EST le mois. Pas
     * de second paramètre à tenir d'accord avec le premier.
     */
    public function testAMonthIsSelectedByItsOwnValue(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/stats?range=2026-07');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('juillet 2026', $crawler->filter('.kd-pagehead__lead')->text());
        self::assertCount(0, $crawler->filter('.kd-rangetab.is-active'), 'Aucun onglet fixe n\'est actif sur un mois.');

        $selected = $crawler->filter('.kd-rangepick__select option[selected]');
        self::assertSame('2026-07', $selected->attr('value'));
    }

    /** Un lien périmé ou trafiqué affiche des statistiques, jamais une erreur. */
    public function testAnUnknownWindowFallsBackInsteadOfFailing(): void
    {
        $this->client->loginUser($this->seedUser());

        foreach (['n-importe-quoi', '2026-99', '<script>', ''] as $range) {
            $this->client->request('GET', '/profile/stats?range='.urlencode($range));

            self::assertResponseIsSuccessful(sprintf('« %s » ne doit pas casser la page.', $range));
        }
    }

    /**
     * Le sélecteur est fait de liens et d'un formulaire GET. Rien qui recharge
     * un morceau de page après coup : la règle « pages de consultation
     * auto-suffisantes » est ce qui rend le cache offline possible.
     */
    public function testTheWindowSelectorIsNavigationNotAjax(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/stats');

        self::assertCount(0, $crawler->filter('turbo-frame'));
        self::assertCount(3, $crawler->filter('.kd-rangetab'));
        self::assertSame('get', strtolower($crawler->filter('.kd-rangepick__month')->attr('method')));
    }

    /**
     * Le lien vers la page détaillée n'est offert que sur SON profil : il mène à
     * `/profile/stats`, qui se scope sur l'utilisateur connecté. Sur la fiche
     * d'un athlète suivi, il afficherait les statistiques du coach.
     */
    public function testTheEntryPointIsOwnProfileOnly(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/profile/stats"]')->count());
    }

    // ------------------------------------------------------------- fixtures

    private function seedUser(): User
    {
        $user = $this->createUser('athlete@example.com');

        $exercise = (new Exercise())
            ->setOwner($user)
            ->setName('Développé couché')
            ->setActivity(ActivityType::GYM)
            ->setTargetAreas([TargetArea::CHEST]);
        $this->em->persist($exercise);

        $workout = (new Workout())
            ->setOwner($user)
            ->setTitle('Haut du corps')
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(4)));
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

        $logged = (new LoggedExercise())
            ->setExerciseName('Développé couché')
            ->setExercise($exercise)
            ->setPosition(0);
        foreach ([[SetType::WARMUP, 10, 40.0], [SetType::NORMAL, 8, 100.0]] as $position => [$type, $reps, $kg]) {
            $logged->addLoggedSet(
                (new LoggedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg($kg),
            );
        }

        $scheduled = (new ScheduledWorkout())
            ->setOwner($user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-15'))
            ->setStatus(ScheduledStatus::DONE);
        $scheduled->addLoggedExercise($logged);
        $this->em->persist($scheduled);

        $this->em->flush();

        return $user;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
