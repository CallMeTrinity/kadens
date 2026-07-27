<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\CoachingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Espace coach : accès à l'athlète, création « pour lui » (owner = athlète) et
 * pose sur son calendrier. Couvre aussi l'extension des voters : un coach accepté
 * édite le contenu de son athlète, un coach non accepté est refusé.
 */
final class CoachControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : coaching référence user, il passe donc avant les users.
        foreach ($this->em->getRepository(Coaching::class)->findAll() as $coaching) {
            $this->em->remove($coaching);
        }
        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
        }
        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
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

    /**
     * Il n'y a plus de tableau de bord coach : la liste des athlètes vit sur
     * /coaching. Seule la fiche athlète reste sous /coach, donc sous ROLE_COACH.
     */
    public function testAthletePageForbiddenWithoutCoachRole(): void
    {
        $coach = $this->createUser('nocoach@example.com');
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /** ROLE_COACH ouvre /coach, pas l'accès à un athlète donné. */
    public function testAthletePageForbiddenWithoutAcceptedRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAthletePageShowsAthleteContent(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné 30/30');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fractionné 30/30', $crawler->html());
    }

    /**
     * Le coach a besoin des mesures et records de l'athlète pour programmer : la
     * page athlète rend les mêmes fragments que la page profil, en lecture seule.
     */
    public function testAthletePageShowsAthleteProfileAndStats(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $athlete->setSquat1rmKg(140.0);
        $this->em->flush();
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Fiche athlète', $html);
        self::assertStringContainsString('Statistiques', $html);
        self::assertStringContainsString('140', $html);
        // La fiche appartient à l'athlète : aucun lien d'édition côté coach.
        self::assertStringNotContainsString('/profile/edit', $html);
    }

    /**
     * GoalVoter accorde l'accès au coach accepté, comme les autres voters : sans
     * cette branche, cliquer un objectif depuis la page athlète donnait un 403.
     */
    public function testAcceptedCoachCanViewAthleteGoal(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $goal = (new Goal())
            ->setOwner($athlete)
            ->setTitle('Trail 42k')
            ->setTargetDate(new \DateTimeImmutable('+10 weeks'));
        $this->em->persist($goal);
        $this->em->flush();

        $this->client->loginUser($coach);
        $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertResponseIsSuccessful();

        // Un tiers sans relation reste refusé.
        $this->client->loginUser($this->createUser('stranger@example.com'));
        $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testCoachCreatesWorkoutOwnedByAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/workout/new"]')->form();
        $form['title'] = 'Sortie longue Z2';
        $this->client->submit($form);

        self::assertResponseRedirects();

        $workout = $this->em->getRepository(Workout::class)->findOneBy(['title' => 'Sortie longue Z2']);
        self::assertNotNull($workout);
        self::assertSame($athlete->getId(), $workout->getOwner()->getId());
        // Redirection vers le compositeur normal : pas d'éditeur dédié au coach.
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit');
    }

    public function testCoachCreatesPlanOwnedByAthlete(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/plan/new"]')->form();
        $form['title'] = 'Prépa semi';
        $form['durationWeeks'] = '6';
        $this->client->submit($form);

        $plan = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Prépa semi']);
        self::assertNotNull($plan);
        self::assertSame($athlete->getId(), $plan->getOwner()->getId());
        self::assertSame(6, $plan->getDurationWeeks());
    }

    public function testCoachSchedulesWorkoutOnAthleteCalendar(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Seuil 3x8');

        $date = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/schedule"]')->form();
        $form['workoutId'] = (string) $workout->getId();
        $form['date'] = $date;
        $this->client->submit($form);

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        // La séance datée appartient à l'athlète : elle apparaît sur SON calendrier.
        self::assertSame($athlete->getId(), $scheduled[0]->getOwner()->getId());
        self::assertSame($date, $scheduled[0]->getScheduledDate()->format('Y-m-d'));
    }

    /** Un coach ne pose pas sa propre séance sur le calendrier d'un athlète. */
    public function testCoachCannotScheduleWorkoutHeOwns(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Séance de l\'athlète');
        $coachWorkout = $this->createWorkout($coach, 'Ma séance perso');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $token = $crawler->filter('form[action$="/schedule"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/coach/athlete/'.$athlete->getId().'/schedule', [
            '_token' => $token,
            'workoutId' => (string) $coachWorkout->getId(),
            'date' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
        ]);

        self::assertCount(0, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    public function testCoachInstantiatesPlanOnAthleteCalendar(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);

        $workout = $this->createWorkout($athlete, 'Séance du plan');
        $plan = $this->createPlanTemplate($athlete, 'Bloc 2 semaines', 2);
        $this->createPlanItem($plan, $workout, 1, 1);

        // Lundi ISO : la trame projette semaine 1 / jour 1 sur cette date.
        $monday = new \DateTimeImmutable('monday next week');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach/athlete/'.$athlete->getId());
        $form = $crawler->filter('form[action$="/instantiate"]')->form();
        $form['planId'] = (string) $plan->getId();
        $form['startDate'] = $monday->format('Y-m-d');
        $this->client->submit($form);

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        self::assertSame($athlete->getId(), $scheduled[0]->getOwner()->getId());
        self::assertSame($monday->format('Y-m-d'), $scheduled[0]->getScheduledDate()->format('Y-m-d'));
    }

    // ------------------------------------------------- extension des voters

    public function testAcceptedCoachCanViewAndEditAthleteWorkout(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $workout = $this->createWorkout($athlete, 'Séance suivie');

        $this->client->loginUser($coach);

        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        self::assertResponseIsSuccessful();
    }

    public function testNonAcceptedCoachIsForbiddenOnAthleteWorkout(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);
        $workout = $this->createWorkout($athlete, 'Séance privée');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/workout/'.$workout->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /** Fin de relation = fin des droits, sans toucher au contenu de l'athlète. */
    public function testEndedRelationRevokesAccess(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ENDED);
        $workout = $this->createWorkout($athlete, 'Séance conservée');

        $this->client->loginUser($coach);
        $this->client->request('GET', '/workout/'.$workout->getId());
        self::assertResponseStatusCodeSame(403);

        // Le contenu existe toujours et reste à l'athlète.
        self::assertNotNull($this->em->getRepository(Workout::class)->find($workout->getId()));
    }

    public function testAcceptedCoachCanEditAthletePlan(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $plan = $this->createPlanTemplate($athlete, 'Plan suivi', 4);

        $this->client->loginUser($coach);
        $this->client->request('GET', '/plan-template/'.$plan->getId().'/edit');

        self::assertResponseIsSuccessful();
    }

    /** La relation est dirigée : l'athlète n'hérite pas des droits sur son coach. */
    public function testAthleteHasNoAccessToCoachContent(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $coachWorkout = $this->createWorkout($coach, 'Séance du coach');

        $this->client->loginUser($athlete);
        $this->client->request('GET', '/workout/'.$coachWorkout->getId());

        self::assertResponseStatusCodeSame(403);
    }

    // ------------------------------------------- portée des index bibliothèque

    /**
     * Le contenu composé pour un athlète lui appartient : sans élargissement de
     * portée, il n'apparaissait nulle part dans les index du coach. Il y figure
     * désormais, avec le badge de propriétaire et une facette dédiée.
     */
    public function testCoachIndexListsAthleteWorkouts(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($athlete, 'Fractionné athlète');
        $this->createWorkout($coach, 'Ma séance perso');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Fractionné athlète', $html);
        self::assertStringContainsString('Ma séance perso', $html);
        // Facette de propriétaire, « Moi » active au chargement.
        self::assertSame(
            (string) $coach->getId(),
            $crawler->filter('.kd-libfilter--on[data-facet-group="owner"]')->attr('data-facet-value')
        );
        // Badge sur la carte de l'athlète, absent sur la sienne.
        self::assertCount(1, $crawler->filter('.kd-scope--link'));
    }

    public function testCoachIndexListsAthletePlans(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createPlanTemplate($athlete, 'Prépa athlète', 4);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/plan-template');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Prépa athlète', $crawler->html());
        self::assertSame(
            (string) $coach->getId(),
            $crawler->filter('.kd-libfilter--on[data-facet-group="owner"]')->attr('data-facet-value')
        );
    }

    /** Une demande non acceptée n'ouvre aucune portée. */
    public function testPendingRelationDoesNotWidenIndex(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING);
        $this->createWorkout($athlete, 'Séance privée');

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/workout');

        self::assertStringNotContainsString('Séance privée', $crawler->html());
        // Aucun athlète : le groupe de facette disparaît de la barre de filtres.
        self::assertCount(0, $crawler->filter('[data-facet-group="owner"]'));
    }

    /** La relation reste dirigée : l'athlète ne voit pas la bibliothèque du coach. */
    public function testAthleteIndexDoesNotListCoachWorkouts(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED);
        $this->createWorkout($coach, 'Séance du coach');

        $this->client->loginUser($athlete);
        $crawler = $this->client->request('GET', '/workout');

        self::assertStringNotContainsString('Séance du coach', $crawler->html());
        self::assertCount(0, $crawler->filter('[data-facet-group="owner"]'));
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createCoaching(User $coach, User $athlete, CoachingStatus $status): Coaching
    {
        $coaching = (new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus($status)
            ->setRequestedBy($coach);

        if (CoachingStatus::PENDING !== $status) {
            $coaching->setRespondedAt(new \DateTimeImmutable());
        }

        $this->em->persist($coaching);
        $this->em->flush();

        return $coaching;
    }

    private function createWorkout(User $owner, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace([' ', '\''], '-', $title)));

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function createPlanTemplate(User $owner, string $title, int $weeks): PlanTemplate
    {
        $template = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDurationWeeks($weeks)
            ->setSlug(strtolower(str_replace(' ', '-', $title)));

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    private function createPlanItem(PlanTemplate $template, Workout $workout, int $week, int $day): PlanItem
    {
        $item = (new PlanItem())
            ->setPlanTemplate($template)
            ->setWorkout($workout)
            ->setWeekNumber($week)
            ->setDayOfWeek($day);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
