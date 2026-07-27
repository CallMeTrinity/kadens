<?php

namespace App\Tests\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Enum\TargetArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Balayage de non-régression : toutes les vues doivent répondre 200 après la
 * bascule d'identité (les tokens touchent chaque page).
 */
final class SmokeTest extends WebTestCase
{
    public function testEveryMainViewStillRenders(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach ([PlanTemplate::class, Workout::class, Exercise::class, User::class] as $class) {
            foreach ($em->getRepository($class)->findAll() as $e) {
                $em->remove($e);
            }
        }
        $em->flush();

        $user = (new User())->setEmail('smoke@example.com')->setPassword('x');
        $em->persist($user);

        $exercise = (new Exercise())->setOwner($user)->setName('Soulevé de terre')
            ->setActivity(ActivityType::GYM)->setTargetAreas([TargetArea::GLUTES, TargetArea::HAMSTRINGS]);
        $em->persist($exercise);

        $workout = (new Workout())->setOwner($user)->setTitle('Séance test')->setSlug('seance-test');
        $em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $pe = (new PrescribedExercise())->setExercise($exercise)->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)->setRpe(9)->setRestSeconds(180)
            ->setNotes('Dos gainé.');
        $pe->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(6)->setWeightKg(70.0));
        $pe->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(6)->setWeightKg(140.0));
        $pe->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(6)->setWeightKg(140.0));
        $pe->addDetailedSet((new PrescribedSet())->setPosition(3)->setSetType(SetType::TO_FAILURE)->setReps(6)->setWeightKg(140.0));
        $block->addPrescribedExercise($pe);
        $workout->addBlock($block);
        $em->persist($block);

        $template = (new PlanTemplate())->setOwner($user)->setTitle('Plan test')
            ->setSlug('plan-test')->setDurationWeeks(2);
        $em->persist($template);

        $scheduled = (new ScheduledWorkout())->setOwner($user)->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-03-15'));
        $em->persist($scheduled);
        $em->flush();

        $client->loginUser($user);

        $urls = [
            '/exercise', '/exercise/'.$exercise->getId(),
            '/workout', '/workout/'.$workout->getId(), '/workout/'.$workout->getId().'/edit',
            '/plan-template', '/plan-template/'.$template->getId(), '/plan-template/'.$template->getId().'/edit',
            '/calendar', '/calendar/week', '/schedule/'.$scheduled->getId(),
            '/goal', '/goal/new',
            '/', '/profile/edit', '/profile/settings',
            '/coaching',
        ];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            // Certaines entrées redirigent vers leur forme canonique
            // (/calendar → /calendar/{année}/{mois}) : on suit avant d'assert.
            if ($client->getResponse()->isRedirection()) {
                $client->followRedirect();
            }
            self::assertResponseIsSuccessful(sprintf('La page %s doit répondre 200.', $url));
        }

        // Pages sans authentification.
        $client->request('GET', '/s/seance-test');
        self::assertResponseIsSuccessful('La page publique de séance doit répondre 200.');
        $client->request('GET', '/s/plan/plan-test');
        self::assertResponseIsSuccessful('La page publique de plan doit répondre 200.');
    }
}
