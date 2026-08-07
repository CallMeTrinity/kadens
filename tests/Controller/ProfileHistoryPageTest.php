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
 * La page `/profile/history`.
 *
 * Trois choses s'y jouent :
 *
 * 1. **Elle n'a pas de fenêtre.** Contrairement à `/profile/stats`, la réponse
 *    EST l'étendue complète. Un `?range=` traînant dans un lien doit être
 *    ignoré, pas rejeté — et surtout ne doit rien réduire.
 * 2. **Elle se rend sur un compte vide.** Une page d'historique qui exige de
 *    l'historique casse le premier jour.
 * 3. **Elle est auto-suffisante.** Aucun fragment rechargé, aucun `turbo-frame` :
 *    tous les mois sont dans la réponse, ce qui est la condition du cache
 *    offline.
 */
final class ProfileHistoryPageTest extends WebTestCase
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
        $this->client->request('GET', '/profile/history');

        self::assertResponseRedirects('/login');
    }

    /** Un compte neuf : pas d'historique, un état vide dessiné, aucune erreur. */
    public function testRendersOnAnEmptyAccount(): void
    {
        $this->client->loginUser($this->createUser('new@example.com'));
        $crawler = $this->client->request('GET', '/profile/history');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.kd-empty')->count());
        self::assertCount(0, $crawler->filter('.kd-histgrid'));
    }

    /**
     * Le rendu nominal : une grille par mois, et la légende des cinq groupes
     * plus les deux marques qui ne sont pas des groupes — « sans réalisé » et
     * l'endurance, qui se dit par ses icônes.
     */
    public function testRendersTheGridAndItsLegend(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/history');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.kd-histgrid')->count());
        self::assertCount(7, $crawler->filter('.kd-histlegend .kd-legend__item'));
        self::assertCount(1, $crawler->filter('.kd-histlegend .kd-histdot--bare'));
        self::assertCount(1, $crawler->filter('.kd-histlegend .kd-legend__item--icon'));
    }

    /**
     * Chaque séance affichée est un LIEN vers sa page. C'est ce qui sépare cette
     * vue d'une carte de chaleur : on vient y retrouver une séance, et on doit
     * pouvoir l'ouvrir.
     */
    public function testEverySessionIsAClickableLink(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/history');

        $links = $crawler->filter('.kd-histday .kd-histsess');
        self::assertGreaterThan(0, $links->count());

        foreach ($links as $link) {
            self::assertMatchesRegularExpression(
                '#^/schedule/\d+$#',
                $link->getAttribute('href'),
                'Une séance doit mener à sa propre page.',
            );
            self::assertNotSame('', $link->getAttribute('aria-label'));
        }
    }

    /**
     * Deux séances le même jour font deux liens dans la même case, et une sortie
     * d'endurance se distingue par son icône — sans quoi elle n'aurait que la
     * case creuse d'une séance non loguée, alors qu'elle a bien eu lieu.
     */
    public function testACardioAndAGymSessionShareTheirDayWithDistinctMarks(): void
    {
        $this->client->loginUser($this->seedUser(withCardioSameDay: true));
        $crawler = $this->client->request('GET', '/profile/history');

        $busy = $crawler->filter('.kd-histday--done')->reduce(
            static fn ($node): bool => $node->filter('.kd-histsess')->count() > 1,
        );

        self::assertCount(1, $busy, 'Le jour à deux séances doit porter deux liens.');
        self::assertCount(1, $busy->filter('.kd-histsess--endurance'), 'La sortie se distingue.');
        self::assertCount(1, $busy->filter('.kd-histsess:not(.kd-histsess--endurance) .kd-muscle--chest'));
    }

    /**
     * Le décompte par nature : chaque séance comptée une fois, sous son
     * activité. C'est la réponse à « combien de séances de salle, combien de
     * courses ».
     */
    public function testTheTalliesAreBrokenDownByActivity(): void
    {
        $this->client->loginUser($this->seedUser(withCardioSameDay: true));
        $crawler = $this->client->request('GET', '/profile/history');

        $labels = $crawler->filter('.kd-metric__label')->each(static fn ($n): string => trim($n->text()));

        self::assertContains('Salle de sport', $labels);
        self::assertContains('Course à pied', $labels);
    }

    /**
     * Un jour de salle logué porte ses pastilles de groupe ; le développé
     * couché cible les pectoraux, donc la classe `kd-muscle--chest`. C'est le
     * seul écran du projet qui emploie de vraies couleurs — s'il cessait de les
     * poser, l'exception ne servirait plus à rien.
     */
    public function testALoggedDayCarriesItsMuscleDots(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/history');

        self::assertGreaterThan(0, $crawler->filter('.kd-histday--done .kd-histdot.kd-muscle--chest')->count());
    }

    /**
     * La page n'a pas de fenêtre : un `?range=` — périmé, copié depuis
     * `/profile/stats`, ou trafiqué — est ignoré, et n'ampute pas la grille.
     */
    public function testTheRangeParameterIsIgnoredNotHonoured(): void
    {
        $this->client->loginUser($this->seedUser());

        $reference = $this->client->request('GET', '/profile/history')->filter('.kd-histgrid')->count();
        self::assertGreaterThan(0, $reference);

        foreach (['4w', '2026-07', 'n-importe-quoi', '<script>'] as $range) {
            $crawler = $this->client->request('GET', '/profile/history?range='.urlencode($range));

            self::assertResponseIsSuccessful(sprintf('« %s » ne doit pas casser la page.', $range));
            self::assertCount(
                $reference,
                $crawler->filter('.kd-histgrid'),
                'La grille ne se laisse pas borner par un paramètre qu\'elle n\'a pas.',
            );
        }
    }

    /**
     * Tous les mois sont dans la réponse : rien n'est chargé après coup. C'est
     * ce qui rend la page cachable hors ligne, comme le reste des vues de
     * consultation.
     */
    public function testThePageIsSelfContained(): void
    {
        $this->client->loginUser($this->seedUser());
        $crawler = $this->client->request('GET', '/profile/history');

        self::assertCount(0, $crawler->filter('turbo-frame'));

        // Le contenu de la page ne porte aucun contrôleur Stimulus : tous les
        // mois sont déjà là. (L'en-tête partagé en a un, pour son menu — il ne
        // charge rien.)
        self::assertCount(0, $crawler->filter('.kd-histyear [data-controller]'));
        self::assertCount(0, $crawler->filter('.kd-histlegend [data-controller]'));
    }

    /**
     * L'entrée se fait depuis le **profil** — la page d'accueil — autant que
     * depuis les statistiques, et les deux retours sont offerts. Un historique
     * qu'il faut atteindre en deux sauts n'est pas consulté.
     */
    public function testItIsReachableFromTheProfileAndFromTheStatsPage(): void
    {
        $this->client->loginUser($this->seedUser());

        $profile = $this->client->request('GET', '/');
        self::assertGreaterThan(0, $profile->filter('a[href="/profile/history"]')->count());

        $stats = $this->client->request('GET', '/profile/stats');
        self::assertGreaterThan(0, $stats->filter('a[href="/profile/history"]')->count());

        $history = $this->client->request('GET', '/profile/history');
        self::assertGreaterThan(0, $history->filter('a[href="/profile/stats"]')->count());
        self::assertGreaterThan(0, $history->filter('a[href="/"]')->count());
    }

    // ---------------------------------------------------------------- helpers

    private function seedUser(bool $withCardioSameDay = false): User
    {
        $user = $this->createUser('athlete-history@example.com');

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

        // Une séance faite le mois dernier, loguée : elle doit porter ses
        // pastilles. La grille court donc de ce mois-là au mois courant.
        $scheduled = (new ScheduledWorkout())
            ->setOwner($user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('-1 month'))
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName('Développé couché')
            ->setPosition(0);
        $logged->addLoggedSet(
            (new LoggedSet())->setPosition(0)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0),
        );
        $scheduled->addLoggedExercise($logged);

        $this->em->persist($scheduled);

        // Une sortie course LE MÊME JOUR : elle ne se logue pas, elle doit
        // pourtant apparaître, distincte, à côté de la séance de salle.
        if ($withCardioSameDay) {
            $run = (new Exercise())
                ->setOwner($user)
                ->setName('Footing')
                ->setActivity(ActivityType::RUNNING);
            $this->em->persist($run);

            $outing = (new Workout())
                ->setOwner($user)
                ->setTitle('Sortie du soir')
                ->setSlug('sortie-'.bin2hex(random_bytes(4)));
            $runBlock = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
            $runBlock->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($run)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                    ->setDistanceMeters(5000)
                    ->setDurationSeconds(1500),
            );
            $outing->addBlock($runBlock);
            $this->em->persist($outing);
            $this->em->persist($runBlock);

            $this->em->persist(
                (new ScheduledWorkout())
                    ->setOwner($user)
                    ->setWorkout($outing)
                    ->setScheduledDate($scheduled->getScheduledDate())
                    ->setStatus(ScheduledStatus::DONE),
            );
        }
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
