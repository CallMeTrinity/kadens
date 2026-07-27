<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Repository\GoalRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Service\CoachingResolver;
use App\Service\HeartRateZones;
use App\Service\PlanScheduler;
use App\Service\ProfileStats;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace de travail du coach (ROLE_COACH, cf. access_control).
 *
 * Il n'y a **pas de tableau de bord ici** : la liste des athlètes et la gestion
 * des relations vivent sur `/coaching`, page unique pour les deux sens de la
 * relation. `/coach` ne porte que la fiche de travail d'un athlète donné.
 *
 * Principe directeur : **le contenu créé ici est possédé par l'athlète**
 * (`setOwner($athlete)`). Ces actions sont volontairement minces — elles créent
 * la coquille puis renvoient vers les éditeurs habituels (compositeur de séance,
 * éditeur de plan), qui fonctionnent tels quels pour le coach une fois les voters
 * étendus à la relation acceptée. On ne duplique aucun éditeur.
 *
 * Conséquence : tout apparaît d'office chez l'athlète (bibliothèque, calendrier),
 * et `PlanScheduler::resync()` reste cohérent puisque son repli sur
 * `$template->getOwner()` désigne bien l'athlète.
 */
#[Route('/coach')]
final class CoachController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachingResolver $coachingResolver,
    ) {
    }

    /**
     * Vue d'un athlète suivi : sa bibliothèque, ses plans, ses prochaines séances
     * datées. C'est le point de départ de toutes les actions « pour lui ».
     */
    #[Route('/athlete/{id}', name: 'app_coach_athlete', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function athlete(
        #[MapEntity(id: 'id')] User $athlete,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        GoalRepository $goalRepository,
        ProfileStats $profileStats,
        HeartRateZones $heartRateZones,
    ): Response {
        $this->denyUnlessCoachOf($athlete);

        $today = new \DateTimeImmutable('today');

        return $this->render('coach/athlete.html.twig', [
            'athlete' => $athlete,
            'workouts' => $workoutRepository->findLibraryForOwner($athlete),
            'plans' => $planTemplateRepository->findBy(['owner' => $athlete], ['title' => 'ASC']),
            'upcoming' => $scheduledWorkoutRepository->findByOwnerBetween($athlete, $today, $today->modify('+8 weeks')),
            'goals' => $goalRepository->findUpcomingForOwner($athlete, 3),
            // Mêmes services que la page profil : ils prennent n'importe quel User.
            // Le coach a besoin des 1RM, records et zones cardio pour programmer.
            'stats' => $profileStats->for($athlete),
            'hrZones' => $heartRateZones->forUser($athlete),
        ]);
    }

    /**
     * Crée une séance vide **pour** l'athlète et bascule sur le compositeur normal.
     * Le coach y a accès parce que WorkoutVoter accorde EDIT au coach accepté du
     * propriétaire.
     */
    #[Route('/athlete/{id}/workout/new', name: 'app_coach_workout_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newWorkout(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        SlugGenerator $slugGenerator,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_workout_new'.$athlete->getId());

        $title = trim($request->getPayload()->getString('title')) ?: 'Nouvelle séance';

        $workout = (new Workout())
            ->setOwner($athlete)
            ->setTitle($title)
            ->setSlug($slugGenerator->generate($title, Workout::class));

        $this->entityManager->persist($workout);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Séance créée pour %s. Compose-la maintenant.', $athlete->getUserIdentifier()));

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }

    /**
     * Crée un plan vide pour l'athlète et bascule sur l'éditeur de plan normal.
     */
    #[Route('/athlete/{id}/plan/new', name: 'app_coach_plan_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newPlan(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        SlugGenerator $slugGenerator,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_plan_new'.$athlete->getId());

        $payload = $request->getPayload();
        $title = trim($payload->getString('title')) ?: 'Nouveau plan';
        $weeks = max(1, min(52, $payload->getInt('durationWeeks', 4)));

        $template = (new PlanTemplate())
            ->setOwner($athlete)
            ->setTitle($title)
            ->setDurationWeeks($weeks)
            ->setSlug($slugGenerator->generate($title, PlanTemplate::class));

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Plan créé pour %s. Compose sa trame maintenant.', $athlete->getUserIdentifier()));

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
    }

    /**
     * Pose une séance de la bibliothèque de l'athlète sur une date de SON
     * calendrier. Calqué sur `ScheduledWorkoutController::place`, à ceci près que
     * l'owner posé est l'athlète, pas l'utilisateur courant.
     */
    #[Route('/athlete/{id}/schedule', name: 'app_coach_schedule', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function scheduleWorkout(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        WorkoutRepository $workoutRepository,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_schedule'.$athlete->getId());

        $payload = $request->getPayload();
        $redirect = $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('date')) ?: null;
        $workout = $workoutRepository->find($payload->getInt('workoutId'));

        // Même garde-fou que la pose côté athlète : une copie privée de plan
        // (planLocal) n'est jamais posable seule. On exige en plus que la séance
        // appartienne bien à cet athlète : sans ça, un coach poserait sa propre
        // séance (ou celle d'un autre athlète) sur ce calendrier.
        if (null === $date || null === $workout || $workout->isPlanLocal() || $workout->getOwner() !== $athlete) {
            $this->addFlash('error', 'Impossible de planifier cette séance.');

            return $redirect;
        }

        $scheduled = (new ScheduledWorkout())
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setOwner($athlete)
            ->setStatus(ScheduledStatus::PLANNED);

        $this->entityManager->persist($scheduled);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Séance posée au %s sur le calendrier de %s.', $date->format('d/m/Y'), $athlete->getUserIdentifier()));

        return $redirect;
    }

    /**
     * Instancie un plan de l'athlète sur son calendrier. `PlanScheduler` prend un
     * owner explicite : on lui passe l'athlète, donc l'instanciation et les resync
     * ultérieurs restent les siens.
     */
    #[Route('/athlete/{id}/instantiate', name: 'app_coach_instantiate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function instantiatePlan(
        Request $request,
        #[MapEntity(id: 'id')] User $athlete,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
    ): Response {
        $this->denyUnlessCoachOf($athlete);
        $this->denyUnlessCsrf($request, 'coach_instantiate'.$athlete->getId());

        $payload = $request->getPayload();
        $redirect = $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);

        $template = $planTemplateRepository->find($payload->getInt('planId'));
        $startDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('startDate')) ?: null;

        if (null === $template || null === $startDate || $template->getOwner() !== $athlete) {
            $this->addFlash('error', 'Impossible d\'instancier ce plan.');

            return $redirect;
        }

        $alreadyOnCalendar = $planScheduler->isInstantiated($template, $athlete);
        $created = $planScheduler->instantiate($template, $athlete, $startDate);

        $this->addFlash('success', $alreadyOnCalendar
            ? sprintf('Plan « %s » resynchronisé : %d nouvelle(s) séance(s).', $template->getTitle(), \count($created))
            : sprintf('Plan « %s » posé chez %s : %d séance(s).', $template->getTitle(), $athlete->getUserIdentifier(), \count($created)));

        return $redirect;
    }

    /**
     * Garde-fou commun : l'utilisateur courant doit être coach **accepté** de cet
     * athlète. ROLE_COACH seul ne donne accès à personne — il ouvre la porte de
     * /coach, pas celle d'un athlète donné.
     */
    private function denyUnlessCoachOf(User $athlete): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->coachingResolver->isAcceptedCoachOf($user, $athlete)) {
            throw $this->createAccessDeniedException('Tu n\'es pas le coach de cet athlète.');
        }
    }

    private function denyUnlessCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
