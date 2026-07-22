<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
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
        $this->client->submitForm('Ajouter le bloc');

        $this->em->clear();
        $workout = $this->em->getRepository(Workout::class)->find($workout->getId());
        self::assertCount(1, $workout->getBlocks());
        $blockId = $workout->getBlocks()->first()->getId();

        // Ajout d'un exercice SETS_REPS ; on renseigne aussi distanceMeters
        // (hors sous-ensemble) pour vérifier qu'il est annulé côté serveur.
        $this->client->request('GET', '/workout/'.$workout->getId().'/edit');
        $this->client->submitForm('Ajouter l\'exercice', [
            'add_exercise_'.$blockId.'[exercise]' => $exercise->getId(),
            'add_exercise_'.$blockId.'[prescriptionType]' => PrescriptionType::SETS_REPS->value,
            'add_exercise_'.$blockId.'[sets]' => '4',
            'add_exercise_'.$blockId.'[reps]' => '8',
            'add_exercise_'.$blockId.'[weightKg]' => '60',
            'add_exercise_'.$blockId.'[distanceMeters]' => '999',
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
