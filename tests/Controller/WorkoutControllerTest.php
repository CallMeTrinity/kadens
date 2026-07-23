<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WorkoutControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

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
        $this->client->request('GET', '/workout');

        self::assertResponseRedirects('/login');
    }

    public function testCreateWorkoutGeneratesSlug(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/workout/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Créer', [
            'workout[title]' => 'Séance jambes',
        ]);

        $created = $this->em->getRepository(Workout::class)->findOneBy(['title' => 'Séance jambes']);
        self::assertNotNull($created);
        self::assertSame('seance-jambes', $created->getSlug());
        self::assertResponseRedirects('/workout/'.$created->getId().'/edit');
    }

    public function testAddBlockThenExerciseNullsIrrelevantFields(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $workout = $this->createWorkout($user, 'Séance jambes');
        $this->client->loginUser($user);

        // Ajout d'un bloc via le formulaire de la page d'édition.
        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $this->client->submitForm('Ajouter un bloc');

        $this->em->clear();
        $workout = $this->em->getRepository(Workout::class)->find($workout->getId());
        self::assertCount(1, $workout->getBlocks());
        $blockId = $workout->getBlocks()->first()->getId();

        // Ajout express depuis la bibliothèque (type SETS_REPS par défaut) : le
        // « + »/glisser-déposer poste le formulaire caché quick-add.
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $quickAddToken = $crawler->filter('form[data-composer-target="quickAddForm"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/workout/'.$workout->getId().'/exercises/quick-add', [
            '_token' => $quickAddToken,
            'exerciseId' => $exercise->getId(),
            'blockId' => $blockId,
        ]);

        $this->em->clear();
        $prescribed = $this->em->getRepository(PrescribedExercise::class)->findOneBy([]);
        self::assertNotNull($prescribed);
        self::assertSame(PrescriptionType::SETS_REPS, $prescribed->getPrescriptionType());
        $prescribedId = $prescribed->getId();

        // Affinage inline (panneau paramètres) ; on renseigne aussi distanceMeters
        // (hors sous-ensemble) pour vérifier qu'il est annulé côté serveur.
        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $this->client->submitForm('Enregistrer l\'exercice', [
            'prescribed_'.$prescribedId.'[sets]' => '4',
            'prescribed_'.$prescribedId.'[reps]' => '8',
            'prescribed_'.$prescribedId.'[weightKg]' => '60',
            'prescribed_'.$prescribedId.'[distanceMeters]' => '999',
        ]);

        $this->em->clear();
        $prescribed = $this->em->getRepository(PrescribedExercise::class)->findOneBy([]);
        self::assertNotNull($prescribed);
        self::assertSame(4, $prescribed->getSets());
        self::assertSame(8, $prescribed->getReps());
        self::assertSame(60.0, $prescribed->getWeightKg());
        self::assertNull($prescribed->getDistanceMeters(), 'Le champ hors type doit être annulé.');

        // L'éditeur se rend correctement avec un exercice prescrit existant
        // (formulaire d'édition inline + affichage dynamique des champs).
        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="prescribed_'.$prescribed->getId().'"]');
    }

    public function testQuickAddCreatesPrescribedWithDefaultType(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $workout = $this->createWorkout($user, 'Séance jambes');
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);
        $this->em->flush();
        $blockId = $block->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $token = $crawler->filter('form[data-composer-target="quickAddForm"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/workout/'.$workout->getId().'/exercises/quick-add', [
            '_token' => $token,
            'exerciseId' => $exercise->getId(),
            'blockId' => $blockId,
        ]);
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit');

        $this->em->clear();
        $prescribed = $this->em->getRepository(PrescribedExercise::class)->findOneBy([]);
        self::assertNotNull($prescribed);
        self::assertSame(PrescriptionType::SETS_REPS, $prescribed->getPrescriptionType());
        self::assertSame($blockId, $prescribed->getBlock()->getId());
        self::assertNull($prescribed->getSets(), 'Ajout express : aucune valeur posée, à affiner ensuite.');
    }

    public function testQuickAddRejectsForeignExercise(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $foreignExercise = $this->createExercise($stranger, 'Exo privé');
        $workout = $this->createWorkout($owner, 'Séance');
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);
        $this->em->flush();

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $token = $crawler->filter('form[data-composer-target="quickAddForm"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/workout/'.$workout->getId().'/exercises/quick-add', [
            '_token' => $token,
            'exerciseId' => $foreignExercise->getId(),
            'blockId' => $block->getId(),
        ]);

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(PrescribedExercise::class)->findOneBy([]),
            'Un exercice appartenant à un autre membre ne doit pas être ajouté.',
        );
    }

    public function testReorderMovesPrescribedAcrossBlocks(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $workout = $this->createWorkout($user, 'Séance');

        $blockA = (new Block())->setRole(BlockRole::WARMUP)->setRounds(1)->setPosition(0);
        $blockB = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(1);
        $moved = $this->makePrescribed($exercise, 0);
        $stay = $this->makePrescribed($exercise, 1);
        $anchor = $this->makePrescribed($exercise, 0);
        $blockA->addPrescribedExercise($moved);
        $blockA->addPrescribedExercise($stay);
        $blockB->addPrescribedExercise($anchor);
        $workout->addBlock($blockA);
        $workout->addBlock($blockB);
        $this->em->persist($blockA);
        $this->em->persist($blockB);
        $this->em->flush();

        $movedId = $moved->getId();
        $anchorId = $anchor->getId();
        $blockBId = $blockB->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $token = $crawler->filter('form[data-composer-target="reorderForm"] input[name="_token"]')->attr('value');

        // Déplacer « moved » dans le bloc B, juste après « anchor ».
        $this->client->request('POST', '/workout/'.$workout->getId().'/exercises/reorder', [
            '_token' => $token,
            'prescribedId' => $movedId,
            'targetBlockId' => $blockBId,
            'afterId' => $anchorId,
        ]);
        self::assertResponseRedirects('/workout/'.$workout->getId().'/edit');

        $this->em->clear();
        $moved = $this->em->getRepository(PrescribedExercise::class)->find($movedId);
        $anchor = $this->em->getRepository(PrescribedExercise::class)->find($anchorId);
        self::assertSame($blockBId, $moved->getBlock()->getId(), 'Doit changer de bloc.');
        // Positions denses 0..n : anchor (0) puis moved (1).
        self::assertSame(0, $anchor->getPosition());
        self::assertSame(1, $moved->getPosition());
    }

    /**
     * Contrat dont dépend la mise à jour dynamique : une requête au format stream
     * (Accept text/vnd.turbo-stream.html, ce qu'envoie le contrôleur `composer` en
     * fetch) doit renvoyer un <turbo-stream action="update" target="workout-blocks">
     * portant les blocs frais, et surtout PAS une redirection. Sans ça, l'ajout ne
     * se voit qu'après rechargement.
     */
    public function testQuickAddReturnsTurboStreamForStreamRequest(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Fentes');
        $workout = $this->createWorkout($user, 'Séance');
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);
        $this->em->flush();
        $blockId = $block->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $token = $crawler->filter('form[data-composer-target="quickAddForm"] input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/workout/'.$workout->getId().'/exercises/quick-add',
            ['_token' => $token, 'exerciseId' => $exercise->getId(), 'blockId' => $blockId],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="workout-blocks">', $content);
        self::assertStringContainsString('Fentes', $content);
    }

    public function testShowRendersFlattenedSummary(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Squat');
        $workout = $this->createWorkout($user, 'Séance jambes');

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $prescribed = (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(4)->setReps(8)->setWeightKg(60.0);
        $block->addPrescribedExercise($prescribed);
        $workout->addBlock($block);
        $this->em->persist($block);
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($user);
        $this->client->request('GET', '/workout/'.$workout->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Squat');
        self::assertSelectorTextContains('body', '4 × 8 @ 60 kg');
    }

    public function testShowDeniedToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $workout = $this->createWorkout($owner, 'Séance privée');

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/workout/'.$workout->getId());

        self::assertResponseStatusCodeSame(403);
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

    private function createExercise(User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    private function makePrescribed(Exercise $exercise, int $position): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition($position)
            ->setPrescriptionType(PrescriptionType::SETS_REPS);
    }

    private function createWorkout(User $owner, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)));

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }
}
