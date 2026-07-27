<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * La page d'exécution et la clôture de séance, vues du navigateur.
 *
 * Tout est testé SANS JavaScript : c'est le socle qui doit tenir. Le contrôleur
 * Stimulus n'ajoute que l'enregistrement automatique, l'affichage optimiste et
 * la file hors ligne — si le chemin testé ici casse, la page ne marche plus du
 * tout, JS ou pas.
 */
final class ScheduledWorkoutExecutionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $one) {
            $this->em->remove($one);
        }
        $this->em->flush();
    }

    public function testExecutePageRequiresAuthentication(): void
    {
        [$scheduled] = $this->session();

        $this->client->request('GET', '/schedule/'.$scheduled->getId().'/execute');

        self::assertResponseRedirects('/login');
    }

    public function testExecutePageIsDeniedToSomeoneElse(): void
    {
        [$scheduled] = $this->session();
        $this->client->loginUser($this->createUser('intruder@example.com'));

        $this->client->request('GET', '/schedule/'.$scheduled->getId().'/execute');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Une ligne par série, même en mode scalaire : « 4 × 15 » donne quatre
     * formulaires pointables, pas un compteur.
     */
    public function testExecutePageRendersOneFormPerSet(): void
    {
        [$scheduled, $owner] = $this->session(sets: 4);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId().'/execute');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.kd-execline'));
        self::assertSelectorTextContains('.kd-execprog__count', '0 / 4');
    }

    /**
     * Régression. AUCUN contrôle de cette page ne doit s'appeler `action`.
     *
     * Les contrôles nommés d'un formulaire deviennent des propriétés du <form> et
     * **masquent les siennes** : avec un bouton `name="action"`, `form.action` en
     * JS ne renvoie plus l'URL mais le bouton (ou un `RadioNodeList` s'il y en a
     * deux). Tous les POST partaient sur « /schedule/114/[object HTMLButtonElement] »,
     * tombaient en 404, et s'empilaient dans la file hors ligne sans que rien ne
     * le signale à l'écran.
     *
     * Le bug est côté client, donc invisible d'ici — mais l'invariant qui le rend
     * impossible, lui, se lit dans le HTML rendu.
     */
    public function testNoFormControlIsNamedActionOnTheExecutePage(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 2);
        $this->client->loginUser($owner);

        // Une série validée fait apparaître le second bouton de la ligne, celui
        // qui produisait le RadioNodeList : le cas doit être couvert.
        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
        ]);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId().'/execute');

        self::assertCount(0, $crawler->filter('.kd-exec [name="action"]'));
        // Et le geste continue de porter son intention, sous son nouveau nom.
        self::assertGreaterThan(0, $crawler->filter('.kd-execline__check[name="op"]')->count());
    }

    /**
     * Le geste de base sans JS : le bouton de validation est un vrai submit, il
     * poste la ligne AVEC les valeurs réelles affichées.
     */
    public function testValidatingASetWithoutJavascriptPersistsRealValues(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 3, reps: 15, weightKg: 130.0);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
            'reps' => '12',
            'weightKg' => '120,5',
        ]);

        self::assertResponseRedirects('/schedule/'.$scheduled->getId().'/execute');

        $log = $this->em->getRepository(LoggedSet::class)->findOneBy(['scheduledWorkout' => $scheduled]);
        self::assertNotNull($log);
        self::assertSame(12, $log->getReps());
        // La virgule décimale est ce que produit un clavier français.
        self::assertSame(120.5, $log->getWeightKg());
    }

    /**
     * Le cœur de la demande : terminer une séance au pointage incomplet ne la
     * marque pas faite en silence, ça pose la question.
     */
    public function testFinishingAnIncompleteSessionAsksInsteadOfClosing(): void
    {
        [$scheduled, $owner] = $this->session(sets: 4);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/finish', [
            '_token' => $this->finishToken($scheduled),
        ]);

        self::assertResponseRedirects('/schedule/'.$scheduled->getId().'/execute?prompt=1');

        self::assertSame(ScheduledStatus::PLANNED, $this->reload($scheduled)->getStatus());

        // Sans JS, la question doit être visible sans qu'aucun script ne l'ouvre.
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId().'/execute?prompt=1');
        self::assertCount(1, $crawler->filter('dialog.kd-execdialog[open]'));
    }

    public function testFinishingWithModeAllValidatesTheRemainingSets(): void
    {
        [$scheduled, $owner] = $this->session(sets: 4, reps: 15, weightKg: 130.0);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/finish', [
            '_token' => $this->finishToken($scheduled),
            'mode' => 'all',
        ]);

        self::assertResponseRedirects('/schedule/'.$scheduled->getId());

        $logs = $this->em->getRepository(LoggedSet::class)->findBy(['scheduledWorkout' => $scheduled]);
        self::assertCount(4, $logs);
        self::assertSame(15, $logs[0]->getReps());

        self::assertSame(ScheduledStatus::DONE, $this->reload($scheduled)->getStatus());
    }

    /**
     * « Terminer tel quel » : le manque reste un manque. C'est ce qui remplace
     * l'effacement demandé au départ — avec un réalisé séparé, il n'y a rien à
     * effacer, et la prescription doit rester lisible en face de l'écart.
     */
    public function testFinishingAsIsKeepsTheGapAndThePrescription(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 4, reps: 15, weightKg: 130.0);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
            'reps' => '15',
            'weightKg' => '130',
        ]);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/finish', [
            '_token' => $this->finishToken($scheduled),
            'mode' => 'asis',
        ]);

        self::assertResponseRedirects('/schedule/'.$scheduled->getId());

        self::assertCount(1, $this->em->getRepository(LoggedSet::class)->findBy(['scheduledWorkout' => $scheduled]));

        self::assertSame(ScheduledStatus::DONE, $this->reload($scheduled)->getStatus());

        // La prescription est intacte : c'est elle qui donne son sens à l'écart.
        $fresh = $this->em->getRepository(PrescribedExercise::class)->find($prescribed->getId());
        self::assertSame(4, $fresh->getSets());
        self::assertSame(15, $fresh->getReps());
    }

    /**
     * La fiche datée donne l'entrée vers le pointage, et dit où on en est : on
     * n'aborde pas « démarrer » et « reprendre » de la même façon.
     */
    public function testScheduledSheetLinksToExecutionWithProgress(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 4, reps: 15, weightKg: 130.0);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSelectorTextContains('.kd-wk__start', 'Démarrer la séance');
        self::assertCount(1, $crawler->filter('a.kd-wk__start[href$="/execute"]'));

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
            'reps' => '15',
            'weightKg' => '130',
        ]);

        $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSelectorTextContains('.kd-wk__start', 'Reprendre (1/4)');
    }

    /**
     * Une fois la séance pointée, la fiche datée montre ce qu'on a FAIT, pas ce
     * qu'on avait prévu. Le prévu ne reste qu'en repère là où il diffère.
     *
     * Et la prescription n'est pas touchée pour autant : c'est le rendu qui
     * préfère le log. La page de bibliothèque, qui décrit la même séance sans
     * date, continue d'afficher la prescription — c'est le test suivant.
     */
    public function testDatedSheetShowsRealValuesOnceLogged(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 2, reps: 15, weightKg: 130.0);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
            'reps' => '12',
            'weightKg' => '120',
        ]);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        $first = $crawler->filter('.kd-setrow')->first();
        // La valeur réelle prend la place de la prescrite...
        self::assertStringContainsString('12 reps', $first->text());
        self::assertStringContainsString('120 kg', $first->text());
        // ...et le prévu subsiste en repère, puisqu'il diffère.
        self::assertStringContainsString('prévu 15 reps', $first->text());

        // La série non pointée reste visible, éteinte : l'écart doit se lire.
        self::assertCount(1, $crawler->filter('.kd-setrow.is-missed'));
    }

    /**
     * La page de bibliothèque décrit une prescription : elle n'a pas de date,
     * donc pas de réalisé. Pointer une séance ne doit rien y changer.
     */
    public function testLibraryPageKeepsThePrescriptionAfterLogging(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 2, reps: 15, weightKg: 130.0);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
            'reps' => '12',
            'weightKg' => '120',
        ]);

        $crawler = $this->client->request('GET', '/workout/'.$scheduled->getWorkout()->getId());

        $first = $crawler->filter('.kd-setrow')->first();
        self::assertStringContainsString('15 reps', $first->text());
        self::assertStringNotContainsString('prévu', $first->text());
        self::assertCount(0, $crawler->filter('.kd-setrow.is-missed'));
    }

    public function testResettingClearsTheLogButNotTheStatus(): void
    {
        [$scheduled, $owner, $prescribed] = $this->session(sets: 2, reps: 10, weightKg: 60.0);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log', [
            '_token' => $this->logToken($scheduled),
            'prescribedId' => $prescribed->getId(),
            'setIndex' => 1,
            'op' => 'log',
        ]);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/log/reset', [
            '_token' => $this->resetToken($scheduled),
        ]);

        self::assertResponseRedirects('/schedule/'.$scheduled->getId().'/execute');
        self::assertCount(0, $this->em->getRepository(LoggedSet::class)->findAll());
    }

    // ---- Fixtures -----------------------------------------------------------

    /**
     * @return array{ScheduledWorkout, User, PrescribedExercise}
     */
    private function session(int $sets = 4, int $reps = 15, float $weightKg = 130.0): array
    {
        $owner = $this->createUser(uniqid('athlete', true).'@example.com');

        $exercise = (new Exercise())->setName('Développé couché')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        $workout = (new Workout())->setOwner($owner)->setTitle('Push A')->setSlug(uniqid('push-a', true));
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);

        $prescribed = (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setPosition(0)
            ->setSets($sets)
            ->setReps($reps)
            ->setWeightKg($weightKg);
        $block->addPrescribedExercise($prescribed);

        $this->em->persist($workout);
        $this->em->persist($block);
        $this->em->persist($prescribed);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-27'))
            ->setStatus(ScheduledStatus::PLANNED);
        $this->em->persist($scheduled);
        $this->em->flush();

        return [$scheduled, $owner, $prescribed];
    }

    /**
     * Relit la séance datée depuis la base. Le client de test redémarre le noyau
     * à chaque requête : l'instance d'origine est détachée, `refresh()` échoue
     * dessus. C'est la base qui fait foi après un POST, de toute façon.
     */
    private function reload(ScheduledWorkout $scheduled): ScheduledWorkout
    {
        return $this->em->getRepository(ScheduledWorkout::class)->find($scheduled->getId());
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Jeton CSRF lu DANS la page rendue, comme le ferait un navigateur — et non
     * fabriqué depuis le conteneur, qui n'a pas de session hors requête. Effet
     * utile : on vérifie au passage que le formulaire porte bien le jeton attendu.
     *
     * @param string $selector sélecteur du formulaire dont on veut le jeton
     */
    private function tokenFrom(ScheduledWorkout $scheduled, string $selector): string
    {
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId().'/execute');

        return $crawler->filter($selector.' input[name="_token"]')->first()->attr('value');
    }

    private function logToken(ScheduledWorkout $scheduled): string
    {
        return $this->tokenFrom($scheduled, '.kd-execline__form');
    }

    private function finishToken(ScheduledWorkout $scheduled): string
    {
        return $this->tokenFrom($scheduled, '.kd-execfinish form');
    }

    private function resetToken(ScheduledWorkout $scheduled): string
    {
        return $this->tokenFrom($scheduled, '.kd-exec__reset');
    }
}
