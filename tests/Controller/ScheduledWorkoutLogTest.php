<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Coaching;
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
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'affichage du réalisé sur `/schedule/{id}` (KL-07).
 *
 * Deux natures de tests ici, et la seconde compte autant que la première :
 *
 * 1. **Ce qui s'affiche** : la comparaison en place, l'onglet d'ouverture selon le
 *    statut, la marque d'une séance manquée, la séance libre sans colonne
 *    « Prévu », la suppression du réalisé sans la séance.
 * 2. **Ce qui NE s'affiche pas ailleurs.** Le réalisé n'entre jamais dans
 *    `PlanFlattener`, donc jamais dans la page de bibliothèque, la page publique,
 *    l'export Excel ni le flux ICS. C'est la règle que ce ticket devait vérifier
 *    explicitement — et la seule façon de la vérifier est de la voir échouer si
 *    quelqu'un branche un jour le réalisé sur la mise à plat.
 */
final class ScheduledWorkoutLogTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(Coaching::class)->findAll() as $coaching) {
            $this->em->remove($coaching);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    // ----------------------------------------------------- Ce qui s'affiche

    /**
     * La comparaison est **en place** : une seule table, le prévu et le réalisé
     * côte à côte, une ligne par série. Pas de second tableau ailleurs sur la page.
     */
    public function testLoggedSetsAppearAsAnExtraColumnInTheExistingTable(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, [
            [SetType::NORMAL, 8, 80.0],
            [SetType::NORMAL, 8, 82.5],
            [SetType::NORMAL, 6, 82.5],
        ]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();

        $compared = $crawler->filter('.kd-settable--compared');
        self::assertCount(1, $compared, 'Un seul tableau de comparaison : celui du programme.');

        $headers = $compared->filter('thead th')->each(fn ($th) => trim($th->text()));
        self::assertSame(['Série', 'Prévu', 'Réalisé'], $headers);

        // Trois séries prescrites, trois lignes — la règle « une ligne = une série »
        // tient en mode comparaison comme ailleurs.
        self::assertCount(3, $compared->filter('tbody tr'));

        // Le prescrit reste écrit en face, il ne disparaît pas.
        $planned = $compared->filter('tbody tr')->eq(2)->filter('.kd-setrow__planned');
        self::assertStringContainsString('8 reps', $planned->text());
        self::assertStringContainsString('80 kg', $planned->text());

        $logged = $compared->filter('tbody tr')->eq(2)->filter('.kd-setrow__logged');
        self::assertStringContainsString('6 reps', $logged->text());
        self::assertStringContainsString('82,5 kg', $logged->text());
    }

    /** Le prescrit passe en encre atténuée, mais reste rendu. */
    public function testPrescribedSideIsAttenuatedNotRemoved(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, [[SetType::NORMAL, 8, 80.0]]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        // La classe porte l'atténuation ; le nom de l'exercice, lui, n'est pas
        // atténué (ce n'est pas un paramètre, c'est le sujet de la ligne).
        self::assertGreaterThan(0, $crawler->filter('.kd-exrow--logged')->count());
        self::assertGreaterThan(0, $crawler->filter('.kd-setrow__planned')->count());
        self::assertSelectorTextContains('.kd-exrow--logged .kd-exrow__name', 'Développé couché');
    }

    /**
     * L'onglet d'ouverture dépend du statut. On ne peut pas observer la sélection
     * (le contrôleur Stimulus la fait au navigateur) : ce qu'on garde, c'est ce que
     * le serveur annonce, qui est la seule source de la décision.
     */
    public function testDefaultTabFollowsStatus(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, [[SetType::NORMAL, 8, 80.0]]);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSame('programme', $crawler->filter('.kd-wk__tabs')->attr('data-tabs-default-value'));

        $scheduled->setStatus(ScheduledStatus::DONE);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSame('realise', $crawler->filter('.kd-wk__tabs')->attr('data-tabs-default-value'));
    }

    /** Sans réalisé : pas d'onglet « Réalisé », pas de colonne, pas de bandeau. */
    public function testNoLogMeansNoRealisedTabAtAll(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, []);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('programme', $crawler->filter('.kd-wk__tabs')->attr('data-tabs-default-value'));
        self::assertSelectorNotExists('[data-tabs-name="realise"]');
        self::assertSelectorNotExists('.kd-settable--compared');
        // Le tableau du prescrit, lui, est toujours là.
        self::assertSelectorExists('.kd-settable');
    }

    /** Une séance manquée le dit en clair, sinon elle a l'allure d'une séance à venir. */
    public function testMissedSessionCarriesAnExplicitMark(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, []);
        $scheduled->setStatus(ScheduledStatus::MISSED);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertSelectorTextContains('.kd-wk__missed', 'Séance manquée');
    }

    public function testPlannedSessionCarriesNoMissedMark(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, []);

        $this->client->loginUser($user);
        $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertSelectorNotExists('.kd-wk__missed');
    }

    /**
     * Séance libre : pas de source, donc rien à comparer. La colonne « Prévu »
     * tombe d'elle-même, le titre snapshot tient l'en-tête.
     */
    public function testFreeSessionRendersItsLogWithoutAPlannedColumn(): void
    {
        $user = $this->createUser('owner@example.com');
        $scheduled = (new ScheduledWorkout())
            ->setOwner($user)
            ->setTitle('Séance improvisée')
            ->setScheduledDate(new \DateTimeImmutable('2026-07-30'))
            ->setStatus(ScheduledStatus::DONE);
        $scheduled->addLoggedExercise($this->logged('Tractions', [
            [SetType::NORMAL, 10, null],
            [SetType::NORMAL, 8, null],
        ], null, null));
        $this->em->persist($scheduled);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-wk__title', 'Séance improvisée');

        $headers = $crawler->filter('.kd-settable--compared thead th')->each(fn ($th) => trim($th->text()));
        self::assertSame(['Série', 'Réalisé'], $headers, 'Rien n\'était prévu : pas de colonne « Prévu ».');

        self::assertSelectorTextContains('.kd-logextra__title', 'Exercices réalisés');
        self::assertSelectorTextContains('.kd-exrow__name', 'Tractions');
        // Le bandeau de KPI du réalisé, rendu par le composant partagé.
        self::assertSelectorExists('.kd-wk__kpis--logged');
    }

    /** Un exercice sauté est la seule sortie de rouge du réalisé. */
    public function testSkippedExerciseIsMarkedAsSuch(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled, $prescribed] = $this->createLoggedSession($user, []);

        $skipped = $this->logged('Développé couché', [], $prescribed, $prescribed->getExercise());
        $skipped->setSkipped(true);
        $scheduled->addLoggedExercise($skipped->setPosition(0));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertSelectorTextContains('.kd-dev--skipped', 'Sauté');
    }

    // -------------------------------------------- Suppression du réalisé

    /**
     * Supprimer le réalisé laisse la séance datée debout, avec sa date et son
     * statut. C'est la distinction que l'écran doit rendre évidente et que le
     * contrôleur doit tenir.
     */
    public function testDeletingTheLogKeepsTheDatedSession(): void
    {
        $user = $this->createUser('owner@example.com');
        [$scheduled] = $this->createLoggedSession($user, [[SetType::NORMAL, 8, 80.0]]);
        $scheduled->setStatus(ScheduledStatus::DONE);
        $scheduled->setStartedAt(new \DateTimeImmutable('2026-07-30 18:00'));
        $scheduled->setEndedAt(new \DateTimeImmutable('2026-07-30 19:12'));
        $this->em->flush();
        $id = $scheduled->getId();

        $this->client->loginUser($user);

        // Le jeton se lit sur la page, comme le ferait un navigateur : le
        // gestionnaire CSRF n'a pas de session avant la première requête.
        $crawler = $this->client->request('GET', '/schedule/'.$id);
        self::assertSelectorExists('.kd-logdel');

        $this->client->request('POST', '/schedule/'.$id.'/log/delete', [
            '_token' => $crawler->filter('.kd-logdel input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/schedule/'.$id);
        $this->em->clear();

        $reloaded = $this->em->getRepository(ScheduledWorkout::class)->find($id);
        self::assertNotNull($reloaded, 'La séance datée survit à la suppression de son réalisé.');
        self::assertFalse($reloaded->hasLog());
        self::assertCount(0, $this->em->getRepository(LoggedSet::class)->findAll());
        // Le statut relève de la programmation, pas du réalisé : il ne bouge pas.
        self::assertSame(ScheduledStatus::DONE, $reloaded->getStatus());
        // Les bornes d'exécution, elles, ne mesuraient que ce réalisé.
        self::assertNull($reloaded->getStartedAt());
        self::assertNull($reloaded->getEndedAt());
    }

    /**
     * Le coach accepté lit le réalisé de son athlète, il ne l'efface pas :
     * l'endpoint teste LOG, que le voter réserve au propriétaire (KL-06). Tester
     * EDIT y suffirait syntaxiquement et lui donnerait la main.
     */
    public function testCoachCannotDeleteTheLogButStillReadsIt(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->accept($coach, $athlete);

        [$scheduled] = $this->createLoggedSession($athlete, [[SetType::NORMAL, 8, 80.0]]);
        $id = $scheduled->getId();

        $this->client->loginUser($coach);

        // Il lit : la page s'ouvre et le réalisé y est.
        $this->client->request('GET', '/schedule/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.kd-settable--compared');
        // Et il n'a même pas le bouton.
        self::assertSelectorNotExists('.kd-logdel');

        // Le refus ne dépend pas du jeton : la garde est le voter, et elle tombe
        // avant lui. Un jeton valide ne changerait rien.
        $this->client->request('POST', '/schedule/'.$id.'/log/delete', ['_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertTrue($this->em->getRepository(ScheduledWorkout::class)->find($id)->hasLog());
    }

    // ----------------------------------- Ce qui NE fuite pas (PlanFlattener)

    /**
     * Le test qui échoue si le réalisé fuite dans `PlanFlattener`. Les quatre
     * consommateurs de la mise à plat sont interrogés sur une séance qui PORTE un
     * réalisé reconnaissable : aucun ne doit en rendre la moindre trace.
     */
    public function testLogNeverLeaksThroughPlanFlattener(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setCalendarFeedToken(bin2hex(random_bytes(8)));
        $this->em->flush();

        // 123,5 kg n'est prescrit nulle part : la valeur ne peut venir que du
        // réalisé. Un tonnage agrégé ne suffirait pas — il pourrait coïncider.
        [$scheduled, $prescribed] = $this->createLoggedSession($user, [[SetType::NORMAL, 3, 123.5]]);
        $workout = $prescribed->getBlock()->getWorkout();

        $this->client->loginUser($user);

        // 1. La page de la séance datée : le réalisé y est, c'est le seul endroit.
        $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertStringContainsString('123,5', $this->client->getResponse()->getContent());

        // 2. La séance en bibliothèque : la recette, sans date, utilisée dans N plans.
        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('123,5', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('.kd-settable--compared');

        // 3. La page de partage public.
        $this->client->request('GET', '/s/'.$workout->getSlug());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('123,5', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('.kd-settable--compared');

        // 4. L'export Excel du mois.
        $this->client->request('GET', '/export/schedule/2026/7');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('123,5', $this->client->getResponse()->getContent());

        // 5. Le flux ICS.
        $this->client->request('GET', '/feed/'.$user->getCalendarFeedToken().'.ics');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('123,5', $this->client->getResponse()->getContent());
    }

    // --------------------------------------------------------- Fixtures

    /**
     * Une séance de bibliothèque à un exercice (3 × 8 @ 80 kg), posée au 30/07/2026,
     * et le réalisé décrit par `$sets` (vide = aucun réalisé).
     *
     * @param list<array{SetType, int|null, float|null}> $sets
     *
     * @return array{ScheduledWorkout, PrescribedExercise}
     */
    private function createLoggedSession(User $owner, array $sets): array
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName('Développé couché')
            ->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(4)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $prescribed = (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(3)->setReps(8)->setWeightKg(80.0);
        $block->addPrescribedExercise($prescribed);
        $workout->addBlock($block);
        $this->em->persist($block);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-30'))
            ->setStatus(ScheduledStatus::PLANNED);

        if ([] !== $sets) {
            $scheduled->addLoggedExercise(
                $this->logged('Développé couché', $sets, $prescribed, $exercise)->setPosition(0),
            );
        }

        $this->em->persist($scheduled);
        $this->em->flush();

        return [$scheduled, $prescribed];
    }

    /**
     * @param list<array{SetType, int|null, float|null}> $sets
     */
    private function logged(string $name, array $sets, ?PrescribedExercise $source, ?Exercise $exercise): LoggedExercise
    {
        $logged = (new LoggedExercise())
            ->setExerciseName($name)
            ->setSourcePrescribedExercise($source)
            ->setExercise($exercise)
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

        return $logged;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function accept(User $coach, User $athlete): Coaching
    {
        $coaching = (new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus(CoachingStatus::ACCEPTED);

        $this->em->persist($coaching);
        $this->em->flush();

        return $coaching;
    }
}
