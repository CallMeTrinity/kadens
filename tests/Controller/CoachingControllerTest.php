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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Flux de relation : demande, acceptation, refus, fin. Le contrôle d'accès croisé
 * au contenu (coach accepté = co-éditeur) est couvert par CoachControllerTest.
 */
final class CoachingControllerTest extends WebTestCase
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

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/coaching');

        self::assertResponseRedirects('/login');
    }

    public function testIndexIsOpenToPlainUser(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
    }

    /**
     * Page unique : un coach y voit ses athlètes ET le formulaire d'invitation,
     * qui vivaient auparavant sur un tableau de bord séparé (/coach).
     */
    public function testIndexShowsBothDirectionsForCoach(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED, $coach);

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('athlete@example.com', $html);
        self::assertStringContainsString('Inviter un athlète', $html);
        self::assertStringContainsString('Demander à être coaché', $html);
        // La fiche de travail reste accessible depuis la carte (nom + bouton).
        self::assertGreaterThan(0, $crawler->filter('a[href="/coach/athlete/'.$athlete->getId().'"]')->count());
    }

    /** Un non-coach ne voit ni le formulaire d'invitation ni la section athlètes. */
    public function testIndexHidesCoachSideForPlainUser(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $crawler = $this->client->request('GET', '/coaching');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Inviter un athlète', $crawler->html());
        self::assertStringContainsString('Demander à être coaché', $crawler->html());
    }

    public function testAthleteRequestCreatesPendingRelation(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $this->createUser('coach@example.com', ['ROLE_COACH']);

        $this->client->loginUser($athlete);
        $this->submitRequest('coach@example.com', ['role' => 'athlete']);

        self::assertResponseRedirects('/coaching');

        $relation = $this->em->getRepository(Coaching::class)->findOneBy([]);
        self::assertNotNull($relation);
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
        self::assertSame('coach@example.com', $relation->getCoach()->getEmail());
        self::assertSame('athlete@example.com', $relation->getAthlete()->getEmail());
        self::assertSame($athlete->getId(), $this->reload($relation)->getRequestedBy()->getId());
    }

    public function testRequestTowardsNonCoachIsRejected(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $this->createUser('someone@example.com');

        $this->client->loginUser($athlete);
        $this->submitRequest('someone@example.com', ['role' => 'athlete']);

        self::assertCount(0, $this->em->getRepository(Coaching::class)->findAll());
    }

    public function testSelfRequestIsRejected(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);

        $this->client->loginUser($coach);
        $this->submitRequest('coach@example.com');

        self::assertCount(0, $this->em->getRepository(Coaching::class)->findAll());
    }

    /**
     * Sans champ `role`, un ROLE_COACH est placé du côté coach de la relation :
     * c'est le formulaire « Inviter un athlète » de la page unique.
     */
    public function testCoachInviteCreatesRelationInCoachDirection(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $this->createUser('athlete@example.com');

        $this->client->loginUser($coach);
        $this->submitRequest('athlete@example.com');

        self::assertResponseRedirects('/coaching');

        $relation = $this->em->getRepository(Coaching::class)->findOneBy([]);
        self::assertNotNull($relation);
        self::assertSame($coach->getId(), $relation->getCoach()->getId());
        self::assertSame($coach->getId(), $relation->getRequestedBy()->getId());
    }

    public function testRecipientCanAcceptRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'accept', $this->cardToken($relation, 'respond'));

        self::assertResponseRedirects('/coaching');
        self::assertSame(CoachingStatus::ACCEPTED, $this->reload($relation)->getStatus());
        self::assertNotNull($this->reload($relation)->getRespondedAt());
    }

    public function testRecipientCanDeclineRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->respond($relation, 'decline', $this->cardToken($relation, 'respond'));

        self::assertSame(CoachingStatus::DECLINED, $this->reload($relation)->getStatus());
    }

    /** L'émetteur ne peut pas valider sa propre demande (sinon auto-promotion). */
    public function testRequesterCannotAcceptOwnRequest(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($coach);
        $this->respond($relation, 'accept');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
    }

    public function testThirdPartyCannotRespondOrEnd(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($intruder);

        $this->respond($relation, 'accept');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/coaching/'.$relation->getId().'/end', [
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEitherPartyCanEndRelation(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::ACCEPTED, $coach);

        $this->client->loginUser($athlete);
        $this->client->request('POST', '/coaching/'.$relation->getId().'/end', [
            '_token' => $this->cardToken($relation, 'end'),
        ]);

        self::assertResponseRedirects('/coaching');
        self::assertSame(CoachingStatus::ENDED, $this->reload($relation)->getStatus());
    }

    /** Une relation terminée se réouvre sur la même ligne (UniqueConstraint). */
    public function testEndedRelationIsReopenedInPlace(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $relation = $this->createCoaching($coach, $athlete, CoachingStatus::ENDED, $coach);

        $this->client->loginUser($athlete);
        $this->submitRequest('coach@example.com', ['role' => 'athlete']);

        self::assertCount(1, $this->em->getRepository(Coaching::class)->findAll());
        self::assertSame(CoachingStatus::PENDING, $this->reload($relation)->getStatus());
        self::assertSame($athlete->getId(), $this->reload($relation)->getRequestedBy()->getId());
    }

    public function testDuplicatePendingRequestIsRejected(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->createCoaching($coach, $athlete, CoachingStatus::PENDING, $coach);

        $this->client->loginUser($athlete);
        $this->submitRequest('coach@example.com', ['role' => 'athlete']);

        self::assertCount(1, $this->em->getRepository(Coaching::class)->findAll());
    }

    // ---------------------------------------------------------------- helpers
    //
    // Les jetons CSRF sont lus dans les formulaires rendus (convention du projet,
    // cf. CalendarControllerTest) : les générer hors requête n'a pas de session.
    // Pour les cas « tiers non autorisé », le voter tranche avant la vérification
    // CSRF, un jeton bidon suffit donc.

    /** @param array<string, string> $extra */
    private function submitRequest(string $email, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/coaching');
        $token = $crawler->filter('form[action$="/coaching/request"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/coaching/request', array_merge([
            '_token' => $token,
            'email' => $email,
        ], $extra));
    }

    private function respond(Coaching $relation, string $decision, string $token = 'invalid'): void
    {
        $this->client->request('POST', '/coaching/'.$relation->getId().'/respond', [
            '_token' => $token,
            'decision' => $decision,
        ]);
    }

    /**
     * Relit la relation en base. Le client repart d'un conteneur frais à chaque
     * requête : l'instance de test n'est plus gérée après, `refresh()` échouerait.
     */
    private function reload(Coaching $relation): Coaching
    {
        return $this->em->getRepository(Coaching::class)->find($relation->getId());
    }

    /** Jeton d'une action portée par une carte de relation, lu depuis /coaching. */
    private function cardToken(Coaching $relation, string $action): string
    {
        $crawler = $this->client->request('GET', '/coaching');

        return $crawler
            ->filter(sprintf('form[action$="/coaching/%d/%s"] input[name="_token"]', $relation->getId(), $action))
            ->first()
            ->attr('value');
    }

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

    private function createCoaching(User $coach, User $athlete, CoachingStatus $status, User $requestedBy): Coaching
    {
        $coaching = (new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus($status)
            ->setRequestedBy($requestedBy);

        if (CoachingStatus::PENDING !== $status) {
            $coaching->setRespondedAt(new \DateTimeImmutable());
        }

        $this->em->persist($coaching);
        $this->em->flush();

        return $coaching;
    }
}
