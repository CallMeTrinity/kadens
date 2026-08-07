<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\ExerciseLanguage;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/bootstrap` (KL-14) : l'hydratation complète de la base locale du
 * téléphone.
 *
 * Quatre tests portent le ticket, les autres gardent les bords :
 *
 * 1. `testDeltaKeepsExercisesThatWereNeverUpdated` — le `COALESCE(updatedAt,
 *    createdAt)`. Un filtre naïf sur `updatedAt` passerait tous les autres tests
 *    et ferait disparaître du delta la quasi-totalité de la bibliothèque.
 * 2. `testDeletedThingsAreAnnouncedOnADelta` — sans pierre tombale, la base
 *    locale accumule des fantômes, et rien ne le signale avant des semaines.
 * 3. `testPrivateNotesNeverReachTheApi` — la même garde que l'export Excel, l'ICS
 *    et la page publique, appliquée à une vue de consultation de plus.
 * 4. `testARealisticSetStaysUnderTheBudget` — la réponse tient sous 1 Mo, et le
 *    nombre de requêtes ne dépend pas du volume. C'est la lecture non-flaky de
 *    « moins de 500 ms » : un chronomètre en CI mesure la machine, un compteur de
 *    requêtes mesure le code.
 */
final class ApiBootstrapTest extends WebTestCase
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

    /**
     * Nettoyer AVANT suffit à s'isoler, pas à laisser la base comme on l'a
     * trouvée. Ce fichier est le premier, dans l'ordre alphabétique, à laisser
     * des `Workout` derrière lui : sans ce ménage, ils survivraient jusqu'au run
     * suivant, où le `setUp` d'un fichier lancé seul les retrouverait.
     */
    protected function tearDown(): void
    {
        // Re-résolu : une requête redémarre le noyau, et le gestionnaire
        // d'entités du `setUp` appartient alors à un conteneur éteint.
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        parent::tearDown();
    }

    // --- Le jeu complet -------------------------------------------------------

    public function testBootstrapReturnsTheLibraryTheWindowAndTheHistory(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->createExercise('Rowing barre', $user);
        $this->createExercise('Gainage', null); // bibliothèque globale
        [$scheduled] = $this->createSession($user, new \DateTimeImmutable('-2 days'), logged: [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 8, 80.0],
            [SetType::NORMAL, 6, 82.5],
        ]);

        $payload = $this->bootstrap($this->issueToken($user));

        // La bibliothèque : la perso, la globale, et l'exercice de la séance.
        $names = array_column($payload['exercises'], 'name');
        sort($names);
        self::assertSame(['Développé couché', 'Gainage', 'Rowing barre'], $names);

        // La fenêtre est annoncée en clair : c'est elle que le client remplace.
        self::assertSame((new \DateTimeImmutable('-30 days'))->format('Y-m-d'), $payload['window']['from']);
        self::assertSame((new \DateTimeImmutable('+14 days'))->format('Y-m-d'), $payload['window']['to']);
        self::assertNull($payload['since']);
        self::assertNotEmpty($payload['serverTime']);
        // La langue du compte descend avec la bibliothèque : c'est elle qui dit
        // au téléphone sous quel libellé afficher les `nameEn` qu'il vient de
        // recevoir. Défaut `fr`, comme la colonne.
        self::assertSame('fr', $payload['exerciseLanguage']);

        self::assertCount(1, $payload['schedule']);
        $session = $payload['schedule'][0];

        self::assertSame((string) $scheduled->getUuid(), $session['uuid']);
        self::assertSame('Haut du corps', $session['title']);
        self::assertFalse($session['freeform']);

        // Le prescrit vient de PlanFlattener, `setLines` compris : trois séries
        // déroulées depuis un scalaire « 3 × 8 @ 80 kg », en valeurs brutes.
        $prescribed = $session['blocks'][0]['exercises'][0];
        self::assertSame('Développé couché', $prescribed['name']);
        self::assertCount(3, $prescribed['sets']);
        // assertEquals et non assertSame : JSON ne distingue pas 80 de 80.0.
        self::assertEquals(['index' => 1, 'type' => 'normal', 'reps' => 8, 'weightKg' => 80.0, 'durationSeconds' => null], $prescribed['sets'][0]);

        // Le réalisé voyage dans la même séance datée.
        self::assertCount(1, $session['log']);
        self::assertCount(3, $session['log'][0]['sets']);
        self::assertSame('warmup', $session['log'][0]['sets'][0]['type']);
        self::assertSame(82.5, $session['log'][0]['sets'][2]['weightKg']);
        self::assertSame($prescribed['prescribedId'], $session['log'][0]['sourcePrescribedId']);

        // L'historique : une entrée par exercice pratiqué, en liste (jamais un
        // objet indexé par identifiant, dont json_encode changerait la forme).
        self::assertCount(1, $payload['history']);
        $history = $payload['history'][0];
        self::assertSame($prescribed['exerciseId'], $history['exerciseId']);
        // L'échauffement n'entre ni dans le décompte ni dans le record.
        self::assertSame(2, $history['last']['workingSets']);
        self::assertSame(82.5, $history['best']['weightKg']);

        // Un jeu complet n'a rien à défalquer.
        self::assertSame([], $payload['deleted']['exercises']);
        self::assertSame([], $payload['deleted']['schedule']);
    }

    /**
     * Les deux libellés descendent **toujours les deux**, et c'est la préférence
     * du compte qui dit lequel afficher. Le serveur ne choisit pas à la place du
     * client : il ne peut pas, la bibliothèque est allégée par `?since` alors que
     * la préférence peut changer entre deux pulls — un `name` traduit au pull
     * resterait figé sur toutes les lignes qu'un delta ne remonte pas.
     */
    public function testTheAccountLanguageTravelsWithBothLabels(): void
    {
        $user = $this->createUser('athlete@example.com');
        $user->setExerciseLanguage(ExerciseLanguage::EN);
        $this->createExercise('Tractions en supination', null)->setNameEn('Chin-up');
        $this->createExercise('Dips', null); // le français EST l'anglais : pas de nameEn
        $this->em->flush();

        $payload = $this->bootstrap($this->issueToken($user));

        self::assertSame('en', $payload['exerciseLanguage']);

        $labels = [];
        foreach ($payload['exercises'] as $exercise) {
            $labels[$exercise['name']] = $exercise['nameEn'];
        }

        self::assertSame('Chin-up', $labels['Tractions en supination']);
        self::assertNull($labels['Dips'], 'Un nameEn recopié à l\'identique serait du bruit.');
    }

    /**
     * La fenêtre est le seul filtre des séances datées : ce qui est dehors n'est
     * pas rendu, quelle que soit sa fraîcheur.
     */
    public function testTheWindowIsThirtyDaysBackAndFourteenForward(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->createSession($user, new \DateTimeImmutable('-45 days'));
        [$inside] = $this->createSession($user, new \DateTimeImmutable('+3 days'));
        $this->createSession($user, new \DateTimeImmutable('+40 days'));

        $payload = $this->bootstrap($this->issueToken($user));

        self::assertCount(1, $payload['schedule']);
        self::assertSame((string) $inside->getUuid(), $payload['schedule'][0]['uuid']);
    }

    /** Une séance libre : pas de source, donc pas de programme, mais un réalisé. */
    public function testAFreeformSessionHasNoBlocksAndKeepsItsLog(): void
    {
        $user = $this->createUser('athlete@example.com');

        $scheduled = (new ScheduledWorkout())
            ->setOwner($user)
            ->setTitle('Muscu improvisée')
            ->setScheduledDate(new \DateTimeImmutable('-1 day'))
            ->setStatus(ScheduledStatus::DONE);
        $scheduled->addLoggedExercise(
            (new LoggedExercise())
                ->setExerciseName('Tractions')
                ->setPosition(0)
                ->addLoggedSet((new LoggedSet())->setPosition(0)->setSetType(SetType::NORMAL)->setReps(10)),
        );
        $this->em->persist($scheduled);
        $this->em->flush();

        $session = $this->bootstrap($this->issueToken($user))['schedule'][0];

        self::assertTrue($session['freeform']);
        self::assertSame('Muscu improvisée', $session['title']);
        self::assertSame([], $session['blocks']);
        self::assertSame('Tractions', $session['log'][0]['name']);
        // Hors programme : rien à apparier côté prescrit.
        self::assertNull($session['log'][0]['sourcePrescribedId']);
    }

    /** Une séance sans réalisé rend `null`, pas un réalisé vide. */
    public function testASessionWithoutALogRendersNull(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->createSession($user, new \DateTimeImmutable('+1 day'));

        self::assertNull($this->bootstrap($this->issueToken($user))['schedule'][0]['log']);
    }

    // --- Ce qui n'a pas sa place sur le téléphone -----------------------------

    /**
     * Le filtre de `TrackableSchedule` : le téléphone ne consigne que la muscu,
     * une sortie course n'a donc rien à y faire. Une séance **mixte** reste, elle
     * — c'est bien une séance de renforcement, son cardio s'y lit.
     */
    public function testASessionWithoutStrengthNeverReachesThePhone(): void
    {
        $user = $this->createUser('athlete@example.com');
        $run = $this->createExercise('Footing', $user, activity: ActivityType::RUNNING);

        $this->createSession($user, new \DateTimeImmutable('+1 day'), exercises: [$run]);
        [$strength] = $this->createSession($user, new \DateTimeImmutable('+2 days'));
        [$mixed] = $this->createSession($user, new \DateTimeImmutable('+3 days'), exercises: [
            $this->createExercise('Squat', $user),
            $run,
        ]);

        $uuids = array_column($this->bootstrap($this->issueToken($user))['schedule'], 'uuid');
        sort($uuids);

        $expected = [(string) $strength->getUuid(), (string) $mixed->getUuid()];
        sort($expected);

        self::assertSame($expected, $uuids);
    }

    /**
     * **Le garde-fou qui compte.** La fenêtre fait autorité : ce qu'elle ne
     * contient pas est effacé côté téléphone. Retirer le dernier exercice de
     * muscu d'une séance déjà faite ne doit donc pas emporter son réalisé.
     */
    public function testASessionThatCarriesALogStaysEvenWithoutStrength(): void
    {
        $user = $this->createUser('athlete@example.com');

        [$scheduled] = $this->createSession(
            $user,
            new \DateTimeImmutable('-1 day'),
            logged: [[SetType::NORMAL, 8, 60.0]],
            exercises: [$this->createExercise('Rameur', $user, activity: ActivityType::OTHER)],
        );

        $payload = $this->bootstrap($this->issueToken($user));

        self::assertCount(1, $payload['schedule']);
        self::assertSame((string) $scheduled->getUuid(), $payload['schedule'][0]['uuid']);
    }

    /**
     * Une coquille encore vide descend : il n'y a rien à écarter, et c'est
     * précisément la séance qu'on garnit barre en main (KL-34).
     */
    public function testASessionWithoutAnyPrescribedLineStillReachesThePhone(): void
    {
        $user = $this->createUser('athlete@example.com');
        [$empty] = $this->createSession($user, new \DateTimeImmutable('+1 day'), exercises: []);

        $payload = $this->bootstrap($this->issueToken($user));

        self::assertCount(1, $payload['schedule']);
        self::assertSame((string) $empty->getUuid(), $payload['schedule'][0]['uuid']);
    }

    // --- Le delta -------------------------------------------------------------

    /**
     * **Le test du ticket.** `updatedAt` n'est écrit qu'au `preUpdate` : il reste
     * null tant qu'un exercice n'a jamais été retouché, ce qui est le cas de
     * toute la bibliothèque globale importée en console. Un delta filtré sur
     * `updatedAt` seul la ferait disparaître, et un exercice créé après le dernier
     * bootstrap n'arriverait jamais sur le téléphone.
     */
    public function testDeltaKeepsExercisesThatWereNeverUpdated(): void
    {
        $user = $this->createUser('athlete@example.com');

        $old = $this->createExercise('Vieil exercice jamais retouché', $user);
        $fresh = $this->createExercise('Exercice créé hier, jamais retouché', $user);
        $touched = $this->createExercise('Exercice modifié ce matin', $user);

        // Le jeton s'émet AVANT les horodatages : `stamp()` vide le gestionnaire
        // d'entités, et un `User` détaché ne peut plus porter de nouvel ApiToken.
        $secret = $this->issueToken($user);

        $this->stamp($old, createdAt: '-10 days', updatedAt: null);
        $this->stamp($fresh, createdAt: '-1 day', updatedAt: null);
        $this->stamp($touched, createdAt: '-10 days', updatedAt: '-1 hour');

        $payload = $this->bootstrap(
            $secret,
            since: (new \DateTimeImmutable('-5 days'))->format(\DateTimeInterface::ATOM),
        );

        $names = array_column($payload['exercises'], 'name');
        sort($names);

        self::assertSame([
            'Exercice créé hier, jamais retouché',
            'Exercice modifié ce matin',
        ], $names);
        self::assertNotNull($payload['since']);
    }

    /**
     * Le delta n'allège que la bibliothèque. La fenêtre de séances datées part
     * toujours en entier : la fraîcheur d'un programme n'est portée par aucune
     * colonne (retoucher une série ne date ni sa séance ni la séance datée qui la
     * référence), et un delta qui manque le programme corrigé par le coach est
     * une panne sans symptôme.
     */
    public function testDeltaStillSendsTheWholeWindow(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->createSession($user, new \DateTimeImmutable('-2 days'));

        $payload = $this->bootstrap(
            $this->issueToken($user),
            since: (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
        );

        self::assertCount(1, $payload['schedule']);
    }

    /**
     * **Le second test du ticket.** Sans pierre tombale, un exercice supprimé de
     * la bibliothèque reste proposé par le téléphone pendant des mois.
     */
    public function testDeletedThingsAreAnnouncedOnADelta(): void
    {
        $user = $this->createUser('athlete@example.com');
        $doomedExercise = $this->createExercise('Exercice à supprimer', $user);
        $doomedId = $doomedExercise->getId();
        [$doomedSession] = $this->createSession($user, new \DateTimeImmutable('-2 days'));
        $doomedUuid = (string) $doomedSession->getUuid();

        $since = (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM);

        $this->em->remove($doomedExercise);
        $this->em->remove($doomedSession);
        $this->em->flush();

        $payload = $this->bootstrap($this->issueToken($user), since: $since);

        self::assertSame([$doomedId], $payload['deleted']['exercises']);
        self::assertSame([$doomedUuid], $payload['deleted']['schedule']);
    }

    /**
     * Une pierre tombale ne se dit qu'à qui pouvait voir la chose. Un exercice
     * perso d'un tiers sans relation de coaching n'a jamais été descendu, sa
     * disparition ne regarde personne d'autre.
     */
    public function testDeletedExercisesOfAStrangerAreNotAnnounced(): void
    {
        $user = $this->createUser('athlete@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $theirs = $this->createExercise('Exercice d\'un inconnu', $stranger);

        $since = (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM);

        $this->em->remove($theirs);
        $this->em->flush();

        $payload = $this->bootstrap($this->issueToken($user), since: $since);

        self::assertSame([], $payload['deleted']['exercises']);
    }

    public function testAMalformedSinceIsRejected(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        // « yesterday » est parfaitement compris par DateTimeImmutable : sans le
        // garde-fou de forme, le client obtiendrait un delta plausible et faux.
        foreach (['yesterday', 'pas-une-date', '31/07/2026'] as $raw) {
            $this->client->request('GET', '/api/bootstrap?since='.urlencode($raw), server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
            ]);

            self::assertResponseStatusCodeSame(400, sprintf('« %s » doit être refusé.', $raw));
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        }
    }

    // --- Portée et fuites -----------------------------------------------------

    /**
     * **Le troisième test du ticket.** `Workout.notes` est le fourre-tout du
     * propriétaire seul : il n'entre pas dans `PlanFlattener`, donc ni dans
     * l'export, ni dans l'ICS, ni dans la page publique — et l'API n'y fait pas
     * exception. La consigne d'un exercice prescrit, elle, s'adresse à celui qui
     * l'exécute : elle sort.
     */
    public function testPrivateNotesNeverReachTheApi(): void
    {
        $user = $this->createUser('athlete@example.com');
        [$scheduled, $prescribed] = $this->createSession($user, new \DateTimeImmutable('-1 day'));

        $scheduled->getWorkout()?->setNotes('Brouillon privé : penser à alléger la semaine 3.');
        $prescribed->setNotes('Coudes serrés.');
        $this->em->flush();

        $this->client->request('GET', '/api/bootstrap', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken($user),
        ]);

        $raw = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('Brouillon privé', $raw);
        self::assertStringContainsString('Coudes serrés', $raw);
    }

    /**
     * La portée de la bibliothèque est **symétrique**, celle d'`ExerciseVoter::VIEW` :
     * une séance composée par le coach peut poser ses variantes maison dans le
     * programme de l'athlète, elles doivent donc descendre sur son téléphone.
     */
    public function testTheCoachPersonalLibraryIsVisibleToTheAthlete(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->accept($coach, $athlete);

        $this->createExercise('Variante maison du coach', $coach);

        $names = array_column($this->bootstrap($this->issueToken($athlete))['exercises'], 'name');

        self::assertContains('Variante maison du coach', $names);
    }

    /** Le calendrier ne se partage pas : les séances d'un athlète restent à lui. */
    public function testTheCoachDoesNotGetTheAthleteSchedule(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->accept($coach, $athlete);
        $this->createSession($athlete, new \DateTimeImmutable('-1 day'));

        self::assertSame([], $this->bootstrap($this->issueToken($coach))['schedule']);
    }

    public function testBootstrapRequiresAToken(): void
    {
        $this->client->request('GET', '/api/bootstrap');

        self::assertResponseStatusCodeSame(401);
    }

    // --- Le jeton -------------------------------------------------------------

    /**
     * `lastBootstrapAt` distingue « ce téléphone répond » de « ce téléphone est à
     * jour ». Un `ping` bouge `lastUsedAt` et lui seul.
     */
    public function testOnlyBootstrapMarksTheDeviceAsSynchronised(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $token = $this->em->getRepository(ApiToken::class)->findOneBy(['tokenHash' => ApiToken::hash($secret)]);
        self::assertNotNull($token->getLastUsedAt());
        self::assertNull($token->getLastBootstrapAt(), 'Une sonde ne synchronise rien.');

        $this->client->request('GET', '/api/bootstrap', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $token = $this->em->getRepository(ApiToken::class)->findOneBy(['tokenHash' => ApiToken::hash($secret)]);
        self::assertNotNull($token->getLastBootstrapAt());
    }

    /** `stateless: true` sur `^/api` : aucune session, donc aucun cookie (KL-10). */
    public function testBootstrapSetsNoCookie(): void
    {
        $user = $this->createUser('athlete@example.com');

        $this->client->request('GET', '/api/bootstrap', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken($user),
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmpty($this->client->getResponse()->headers->getCookies());
    }

    // --- Budget ---------------------------------------------------------------

    /**
     * **Le quatrième test du ticket.** Sur un jeu réaliste — 200 exercices, un
     * mois et demi de calendrier chargé, le réalisé de tout le passé — la réponse
     * tient sous 1 Mo, et le nombre de requêtes ne bouge pas avec le volume.
     *
     * Le compteur de requêtes remplace le chronomètre : « moins de 500 ms » se
     * mesure sur une machine, un N+1 se mesure sur le code. C'est celui-ci qui
     * ferait échouer le budget de temps, et lui qu'on garde.
     */
    public function testARealisticSetStaysUnderTheBudget(): void
    {
        $user = $this->createUser('athlete@example.com');

        $exercises = [];
        for ($i = 0; $i < 200; ++$i) {
            $exercises[] = $this->createExercise(sprintf('Exercice de bibliothèque %03d', $i), 0 === $i % 4 ? null : $user, flush: false);
        }
        $this->em->flush();

        for ($day = -30; $day <= 14; $day += 3) {
            $this->createSession(
                $user,
                new \DateTimeImmutable(sprintf('%+d days', $day)),
                logged: $day < 0 ? [[SetType::NORMAL, 8, 80.0], [SetType::NORMAL, 8, 80.0], [SetType::NORMAL, 6, 82.5]] : [],
                exercises: \array_slice($exercises, abs($day) % 100, 5),
                flush: false,
            );
        }
        $this->em->flush();

        $secret = $this->issueToken($user);

        // **Piège de test** : `KernelBrowser` ne redémarre le noyau qu'à partir
        // de la *deuxième* requête. Sans cette sonde intercalée, la mesure se
        // ferait dans le conteneur qui vient de poser les fixtures — carte
        // d'identité tiède, et surtout journal SQL contenant les centaines
        // d'INSERT du jeu d'essai. On compterait le test, pas l'endpoint.
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        $this->client->enableProfiler();
        $this->client->request('GET', '/api/bootstrap', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        self::assertResponseIsSuccessful();

        $bytes = \strlen((string) $this->client->getResponse()->getContent());
        self::assertLessThan(1_000_000, $bytes, sprintf('Charge utile de %d octets.', $bytes));

        $queries = $this->client->getProfile()->getCollector('db')->getQueryCount();
        self::assertLessThan(20, $queries, sprintf('%d requêtes : un N+1 s\'est glissé dans le bootstrap.', $queries));
    }

    // --- Utilitaires ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function bootstrap(string $secret, ?string $since = null): array
    {
        $this->client->request(
            'GET',
            '/api/bootstrap'.(null === $since ? '' : '?since='.urlencode($since)),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret],
        );

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function issueToken(User $owner, string $deviceName = 'Pixel de test'): string
    {
        $secret = ApiToken::generateSecret();

        $this->em->persist(new ApiToken($owner, $deviceName, $secret));
        $this->em->flush();

        return $secret;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function accept(User $coach, User $athlete): void
    {
        $this->em->persist(
            (new Coaching())->setCoach($coach)->setAthlete($athlete)->setStatus(CoachingStatus::ACCEPTED),
        );
        $this->em->flush();
    }

    private function createExercise(
        string $name,
        ?User $owner,
        bool $flush = true,
        ActivityType $activity = ActivityType::GYM,
    ): Exercise {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity($activity);
        $this->em->persist($exercise);

        if ($flush) {
            $this->em->flush();
        }

        return $exercise;
    }

    /**
     * Réécrit les horodatages d'un exercice en SQL direct : `onPrePersist` écrase
     * `createdAt` et `onPreUpdate` écrirait `updatedAt`, on ne peut donc pas
     * fabriquer par l'ORM un exercice « ancien et jamais retouché » — qui est
     * précisément le cas que le delta doit traiter.
     */
    private function stamp(Exercise $exercise, string $createdAt, ?string $updatedAt): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE exercise SET created_at = ?, updated_at = ? WHERE id = ?',
            [
                (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
                null === $updatedAt ? null : (new \DateTimeImmutable($updatedAt))->format('Y-m-d H:i:s'),
                $exercise->getId(),
            ],
        );
        $this->em->clear();
    }

    /**
     * Une séance datée avec son programme et, si on lui en donne, son réalisé.
     *
     * @param list<array{SetType, int|null, float|null}> $logged
     * @param list<Exercise>|null                        $exercises null = un seul « Développé couché » créé pour l'occasion
     *
     * @return array{ScheduledWorkout, PrescribedExercise}
     */
    private function createSession(
        User $owner,
        \DateTimeImmutable $date,
        array $logged = [],
        ?array $exercises = null,
        bool $flush = true,
    ): array {
        $exercises ??= [$this->createExercise('Développé couché', $owner, flush: false)];

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(6)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);

        $first = null;
        foreach ($exercises as $position => $exercise) {
            $prescribed = (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition($position)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(3)->setReps(8)->setWeightKg(80.0);
            $block->addPrescribedExercise($prescribed);
            $first ??= $prescribed;
        }

        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setStatus([] === $logged ? ScheduledStatus::PLANNED : ScheduledStatus::DONE);

        if ([] !== $logged) {
            $loggedExercise = (new LoggedExercise())
                ->setExerciseName($exercises[0]->getName())
                ->setExercise($exercises[0])
                ->setSourcePrescribedExercise($first)
                ->setPosition(0);

            foreach ($logged as $position => [$type, $reps, $weightKg]) {
                $loggedExercise->addLoggedSet(
                    (new LoggedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg($weightKg),
                );
            }

            $scheduled->addLoggedExercise($loggedExercise);
        }

        $this->em->persist($scheduled);

        if ($flush) {
            $this->em->flush();
        }

        return [$scheduled, $first];
    }
}
