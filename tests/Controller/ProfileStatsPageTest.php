<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\ImportedActivity;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivitySource;
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
        self::assertSame('get', strtolower((string) $crawler->filter('.kd-rangepick__month')->attr('method')));
    }

    /**
     * Chaque rubrique est une URL, se marque active, et ne rend que ses blocs.
     * La vue d'ensemble est l'onglet par défaut : son lien n'a pas de `tab`.
     */
    public function testEachTabRendersItsOwnBlocks(): void
    {
        $this->client->loginUser($this->seedUser());

        // La fenêtre est fixée à « Tout » : la séance de la fixture est datée en
        // dur (juillet 2026), et la fenêtre par défaut glisse avec la date du
        // jour. Sans ça, la salle n'a plus de séries à ventiler dès que juillet
        // sort des quatre dernières semaines, et le test dépend du calendrier.
        $cases = [
            '' => ['Vue d\'ensemble', 'Tenu, manqué, à venir', 'Où part le volume'],
            '&tab=gym' => ['Salle', 'Où part le volume', 'Tenu, manqué, à venir'],
            '&tab=endurance' => ['Endurance', 'Ce qui a été parcouru', 'Où part le volume'],
            '&tab=feeling' => ['Ressenti', 'Comment ça s\'est passé', 'Où part le volume'],
        ];

        foreach ($cases as $query => [$label, $shown, $hidden]) {
            $crawler = $this->client->request('GET', '/profile/stats?range=all'.$query);

            self::assertResponseIsSuccessful();
            $active = $crawler->filter('.kd-stattab[aria-current="page"]');
            self::assertCount(1, $active, 'Un seul onglet de rubrique actif à la fois.');
            self::assertSame($label, trim($active->text()));
            self::assertStringContainsString($shown, $crawler->filter('body')->text());
            self::assertStringNotContainsString($hidden, $crawler->filter('body')->text());
        }

        self::assertCount(4, $crawler->filter('.kd-stattab'));
        // L'onglet par défaut garde la fenêtre et n'ajoute pas de `tab`.
        self::assertCount(1, $crawler->filter('a.kd-stattab[href="/profile/stats?range=all"]'));
    }

    /**
     * L'onglet Endurance d'un compte neuf : chaque bloc dessine son état vide,
     * aucun ne suppose une sortie importée.
     */
    public function testTheEnduranceTabRendersOnAnEmptyAccount(): void
    {
        $this->client->loginUser($this->createUser('new@example.com'));
        $crawler = $this->client->request('GET', '/profile/stats?tab=endurance');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(4, $crawler->filter('.kd-empty')->count(), 'Volume, zones, records, prévu contre réel : quatre états vides.');
        self::assertCount(0, $crawler->filter('.kd-endu__coverage'));
    }

    /**
     * Avec une sortie rattachée et une autre en attente : la couverture le dit
     * en tête, les blocs du réel se remplissent, et le propriétaire est invité
     * à rattacher.
     */
    public function testTheEnduranceTabFillsFromTheImportedReal(): void
    {
        $user = $this->createUser('runner@example.com');
        $this->seedOuting($user, new \DateTimeImmutable('-3 days'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/stats?tab=endurance');

        self::assertResponseIsSuccessful();
        $coverage = $crawler->filter('.kd-endu__coverage')->text();
        self::assertStringContainsString('1 sortie sur 1 lue sur la montre', $coverage);
        self::assertStringContainsString('1 activité importée sans séance', $coverage);
        self::assertStringContainsString('rattache-la', $coverage);

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Temps en zones', $body);
        self::assertStringContainsString('Plus longue sortie', $body);
        self::assertStringContainsString('Meilleure allure (≥ 5 km)', $body);
        self::assertStringContainsString('Prévu contre réel', $body);
        self::assertCount(1, $crawler->filter('.kd-prog__stack'), 'Une seule semaine porte une sortie.');
    }

    /** Un `?tab=` inconnu retombe sur la vue d'ensemble, comme une fenêtre inconnue. */
    public function testAnUnknownTabFallsBackToTheOverview(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/stats?tab=n-importe-quoi');

        self::assertResponseIsSuccessful();
        self::assertSame('Vue d\'ensemble', trim($crawler->filter('.kd-stattab[aria-current="page"]')->text()));
    }

    /**
     * La fenêtre et la rubrique se conservent mutuellement : changer l'une ne
     * réinitialise jamais l'autre. C'est la raison d'être d'onglets en liens.
     */
    public function testTheTabAndTheWindowCarryEachOther(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/stats?range=6m&tab=gym');

        // Les onglets de rubrique gardent la fenêtre.
        self::assertCount(1, $crawler->filter('a.kd-stattab[href="/profile/stats?range=6m&tab=endurance"]'));
        // Les onglets de fenêtre gardent la rubrique.
        self::assertCount(1, $crawler->filter('a.kd-rangetab[href="/profile/stats?tab=gym&range=4w"]'));
        // Le formulaire mois aussi.
        self::assertSame('gym', $crawler->filter('.kd-rangepick__month input[name="tab"]')->attr('value'));

        // Sur la vue d'ensemble, rien à porter : les URLs restent propres.
        $crawler = $this->client->request('GET', '/profile/stats?range=6m');
        self::assertCount(1, $crawler->filter('a.kd-rangetab[href="/profile/stats?range=4w"]'));
        self::assertCount(0, $crawler->filter('.kd-rangepick__month input[name="tab"]'));
    }

    /**
     * Le lien vers la page détaillée n'est offert que sur SON profil : il mène à
     * `/profile/stats`, qui se scope sur l'utilisateur connecté. Sur la fiche
     * d'un athlète suivi, il afficherait les statistiques du coach.
     */
    public function testTheEntryPointIsOwnProfileOnly(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile?tab=stats');

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

    /**
     * Une sortie de 6 km prescrite 5 km, faite et rattachée, plus une activité
     * importée le lendemain sans séance.
     */
    private function seedOuting(User $user, \DateTimeImmutable $day): void
    {
        $run = (new Exercise())
            ->setOwner($user)
            ->setName('Footing')
            ->setActivity(ActivityType::RUNNING);
        $this->em->persist($run);

        $workout = (new Workout())
            ->setOwner($user)
            ->setTitle('Footing')
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
        $workout->addBlock($block);
        $this->em->persist($workout);
        $this->em->persist($block);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($user)
            ->setWorkout($workout)
            ->setScheduledDate($day)
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($scheduled);

        $this->em->persist((new ImportedActivity($user, ActivitySource::INTERVALS, 'p1', 'Run', $day->setTime(7, 0), $day))
            ->setActivity(ActivityType::RUNNING)
            ->setDistanceMeters(6000)
            ->setMovingSeconds(1800)
            ->setElevationGainMeters(40)
            ->setHrZoneSeconds([300, 1200, 300, 0, 0])
            ->setScheduledWorkout($scheduled));

        $next = $day->modify('+1 day');
        $this->em->persist((new ImportedActivity($user, ActivitySource::INTERVALS, 'p2', 'Run', $next->setTime(7, 0), $next))
            ->setActivity(ActivityType::RUNNING)
            ->setDistanceMeters(8000)
            ->setMovingSeconds(2400));

        $this->em->flush();
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
