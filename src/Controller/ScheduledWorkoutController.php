<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;
use App\Form\PlanInstantiationType;
use App\Repository\LoggedSetRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Security\Voter\WorkoutVoter;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use App\Service\SessionSheet;
use App\Service\WorkoutLogger;
use App\Service\WorkoutMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Mutations des séances planifiées (instances datées). Les actions redirigent
 * vers le calendrier concerné (dans la vue mémorisée, cf. preferredCalendarView) ;
 * le rendu du planning reste porté par CalendarController. Exception : le
 * changement de statut répond en Turbo Stream (re-render du seul fragment
 * concerné, sans rechargement), avec repli redirection sans JS. Le fragment
 * dépend de la page appelante : pastille de calendrier, ou pastille + section
 * « Réalisé » de la page de séance datée (qui poste `return=schedule`).
 */
#[Route('/schedule')]
final class ScheduledWorkoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Pose une séance de bibliothèque sur une date précise, hors de tout plan.
     * Endpoint lean posté par la modale « poser une séance » du calendrier (une
     * carte cliquée = un submit workoutId+date, calqué sur la palette de plan) :
     * pas de formulaire Symfony, la date vient du « + » du jour choisi.
     */
    #[Route('/place', name: 'app_scheduled_workout_place', methods: ['POST'])]
    public function place(Request $request, WorkoutRepository $workoutRepository): Response
    {
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('schedule_place', $payload->getString('_token'))) {
            return $this->redirectToCurrentMonth();
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('date')) ?: null;
        $workout = $workoutRepository->find($payload->getInt('workoutId'));

        // planLocal = copie privée portée par un plan : jamais posable seule.
        if (null === $date || null === $workout || $workout->isPlanLocal()) {
            $this->addFlash('error', 'Impossible de planifier cette séance.');

            return null === $date ? $this->redirectToCurrentMonth() : $this->redirectToMonth($date);
        }

        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        $scheduled = new ScheduledWorkout();
        $scheduled->setWorkout($workout);
        $scheduled->setScheduledDate($date);
        $scheduled->setOwner($this->getUser());
        $scheduled->setStatus(ScheduledStatus::PLANNED);
        $this->entityManager->persist($scheduled);
        $this->entityManager->flush();

        $this->addFlash('success', 'Séance planifiée.');

        return $this->redirectToMonth($date);
    }

    /**
     * Instancie un plan complet à partir d'une date : PlanScheduler projette
     * la trame sur des dates réelles et crée N ScheduledWorkout.
     */
    #[Route('/plan', name: 'app_scheduled_workout_instantiate', methods: ['POST'])]
    public function instantiate(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
    ): Response {
        $form = $this->createForm(PlanInstantiationType::class, null, [
            'planTemplates' => $planTemplateRepository->findBy(['owner' => $this->getUser()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlanTemplate $template */
            $template = $form->get('planTemplate')->getData();
            /** @var \DateTimeImmutable $startDate */
            $startDate = $form->get('startDate')->getData();

            $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

            $alreadyOnCalendar = $planScheduler->isInstantiated($template, $this->getUser());
            $created = $planScheduler->instantiate($template, $this->getUser(), $startDate);

            $this->addFlash('success', $alreadyOnCalendar
                ? sprintf('Plan resynchronisé : %d nouvelle(s) séance(s) ajoutée(s).', count($created))
                : sprintf('Plan instancié : %d séance(s) planifiée(s).', count($created)));

            return $this->redirectToMonth($startDate);
        }

        $this->addFlash('error', 'Impossible d\'instancier ce plan.');

        return $this->redirectToCurrentMonth();
    }

    /**
     * Consultation d'une séance dans son contexte daté. Distincte de
     * `app_workout_show`, qui montre la séance de **bibliothèque** : celle-ci ne
     * connaît aucune date, donc aucun statut à basculer — une même séance peut
     * être posée sur dix jours différents. C'est cette page qui porte la boucle
     * prévu vs réalisé, et c'est la cible du clic sur une pastille du calendrier.
     *
     * Contexte de rendu identique à WorkoutController::show (même composant de
     * lecture, mêmes services) : la vue ne calcule rien.
     */
    #[Route('/{id}', name: 'app_scheduled_workout_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        ScheduledWorkout $scheduled,
        PlanFlattener $planFlattener,
        WorkoutMetrics $metrics,
        SessionSheet $sheet,
        LoggedSetRepository $loggedSets,
    ): Response {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::VIEW, $scheduled);

        $workout = $scheduled->getWorkout();

        return $this->render('scheduled_workout/show.html.twig', [
            'scheduled' => $scheduled,
            'flat' => $planFlattener->flattenWorkout($workout),
            'summary' => $metrics->summary($workout),
            'blockStats' => $metrics->blockBreakdown($workout),
            // Avancement du pointage : la fiche montre où on en est et donne
            // l'entrée vers la page d'exécution (démarrer ou reprendre).
            'progress' => $sheet->progress($scheduled),
            // Le réalisé. Une fois la séance pointée, c'est LUI qu'on vient
            // relire : le tableau de séries affiche les valeurs réelles et ne
            // garde le prévu qu'en repère là où il diffère. Seule cette page
            // (datée) en fournit — la bibliothèque et la page publique décrivent
            // une prescription, qui n'a pas de date donc pas de réalisé.
            'logs' => $loggedSets->indexedFor($scheduled),
        ]);
    }

    // ---- Exécution : la boucle prévu vs réalisé, série par série -------------

    /**
     * La page qu'on tient en main PENDANT la séance. Distincte de `show`, qui la
     * donne à lire : ici on pointe, on corrige, on termine.
     *
     * Elle n'écrit jamais dans la prescription (voir WorkoutLogger) : valider une
     * série crée un `LoggedSet` daté, ce qui laisse la séance de bibliothèque et
     * toutes ses autres dates intactes.
     *
     * `prompt` est le repli SANS JS de la clôture incomplète : le bouton
     * « Terminer » repasse par ici avec ce drapeau, qui ouvre le choix « tout
     * valider / valider tel quel » côté serveur. Avec JS, la modale s'ouvre sur
     * place et ce chemin ne sert pas.
     */
    #[Route('/{id}/execute', name: 'app_scheduled_workout_execute', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function execute(Request $request, ScheduledWorkout $scheduled, SessionSheet $sheet): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        return $this->render('scheduled_workout/execute.html.twig', [
            'sheet' => $sheet->build($scheduled),
            'scheduled' => $scheduled,
            'prompt' => $request->query->getBoolean('prompt'),
        ]);
    }

    /**
     * Valide, corrige ou dévalide UNE série réalisée. Endpoint lean posté par le
     * contrôleur Stimulus `execlog` (et par de vrais boutons de formulaire sans
     * JS, d'où le repli par redirection).
     *
     * Idempotent côté service : la file d'écriture hors ligne peut rejouer deux
     * fois le même geste après une reconnexion sans créer de doublon.
     */
    #[Route('/{id}/log', name: 'app_scheduled_workout_log', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function log(
        Request $request,
        ScheduledWorkout $scheduled,
        WorkoutLogger $logger,
        SessionSheet $sheet,
    ): Response {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('log'.$scheduled->getId(), $payload->getString('_token'))) {
            return $this->redirectToRoute('app_scheduled_workout_execute', ['id' => $scheduled->getId()]);
        }

        $prescribed = $this->findPrescribed($scheduled, $payload->getInt('prescribedId'));
        $setIndex = $payload->getInt('setIndex');

        // `op` et non `action` : un champ nommé `action` masquerait la propriété
        // `action` du <form> côté JS (voir _exec_line.html.twig).
        if ('unlog' === $payload->getString('op')) {
            $logger->unlog($scheduled, $prescribed, $setIndex);
        } else {
            $logger->log(
                $scheduled,
                $prescribed,
                $setIndex,
                $this->nullableInt($payload, 'reps'),
                $this->nullableFloat($payload, 'weightKg'),
                $this->nullableInt($payload, 'durationSeconds'),
            );
        }

        $this->entityManager->flush();

        // Re-rendu ciblé : la carte de l'exercice concerné + la jauge de
        // progression. Les deux cibles existent sur la page d'exécution, seule
        // page d'où cet endpoint est posté.
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $built = $sheet->build($scheduled);

            return $this->render('scheduled_workout/stream/log.stream.html.twig', [
                'sheet' => $built,
                'scheduled' => $scheduled,
                // L'étape et non l'exercice seul : le panneau affiche aussi son
                // rang et son contexte de bloc.
                'stop' => $sheet->findStop($built, $prescribed->getId()),
            ]);
        }

        return $this->redirectToRoute('app_scheduled_workout_execute', ['id' => $scheduled->getId()]);
    }

    /**
     * Clôture de la séance. Trois entrées possibles, et c'est le cœur de la règle
     * demandée : on ne marque « fait » une séance au pointage incomplet qu'après
     * avoir tranché quoi faire du reste.
     *
     *   mode absent -> séance incomplète : on renvoie sur la page d'exécution avec
     *                  le choix ouvert (repli sans JS ; avec JS la modale a déjà
     *                  posé la question et poste directement l'un des deux modes).
     *                  Séance complète : rien à demander, on termine.
     *   mode=all    -> « j'ai tout fait comme prévu » : les séries manquantes sont
     *                  validées avec les valeurs prescrites, puis la séance passe
     *                  à « faite ».
     *   mode=asis   -> « je termine tel quel » : le manque reste enregistré comme
     *                  écart, la séance passe quand même à « faite ».
     *
     * Le troisième choix de la demande initiale (« effacer les séries vides »,
     * qui supprimait l'exercice s'il ne restait rien) n'existe pas ici : avec un
     * réalisé séparé, une série non validée n'a rien à effacer — elle est déjà
     * absente du réalisé, et la prescription doit rester intacte pour que l'écart
     * soit lisible.
     */
    #[Route('/{id}/finish', name: 'app_scheduled_workout_finish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function finish(
        Request $request,
        ScheduledWorkout $scheduled,
        WorkoutLogger $logger,
        SessionSheet $sheet,
    ): Response {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('finish'.$scheduled->getId(), $payload->getString('_token'))) {
            return $this->redirectToRoute('app_scheduled_workout_execute', ['id' => $scheduled->getId()]);
        }

        $mode = $payload->getString('mode');
        $progress = $sheet->progress($scheduled);

        if ('' === $mode && !$progress['complete']) {
            return $this->redirectToRoute('app_scheduled_workout_execute', [
                'id' => $scheduled->getId(),
                'prompt' => 1,
            ]);
        }

        $added = 'all' === $mode ? $logger->completeAll($scheduled) : 0;

        $scheduled->setStatus(ScheduledStatus::DONE);
        $this->entityManager->flush();

        $this->addFlash('success', $added > 0
            ? sprintf('Séance terminée : %d série(s) validée(s) au passage.', $added)
            : 'Séance terminée.');

        return $this->redirectToRoute('app_scheduled_workout_show', ['id' => $scheduled->getId()]);
    }

    /**
     * Remet le pointage à zéro (le statut de la séance n'est pas touché : le
     * réalisé et le statut sont deux choses distinctes, on peut vouloir
     * recommencer le pointage d'une séance déjà marquée faite).
     */
    #[Route('/{id}/log/reset', name: 'app_scheduled_workout_log_reset', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetLog(Request $request, ScheduledWorkout $scheduled, WorkoutLogger $logger): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        if ($this->isCsrfTokenValid('log_reset'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $removed = $logger->reset($scheduled);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Pointage remis à zéro (%d série(s)).', $removed));
        }

        return $this->redirectToRoute('app_scheduled_workout_execute', ['id' => $scheduled->getId()]);
    }

    /**
     * Retrouve un exercice prescrit DANS la séance datée. Même garde que
     * WorkoutController::findPrescribed : l'id transite par le formulaire, il ne
     * doit pas permettre de pointer l'exercice d'une autre séance.
     */
    private function findPrescribed(ScheduledWorkout $scheduled, int $prescribedId): PrescribedExercise
    {
        foreach ($scheduled->getWorkout()->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                if ($prescribed->getId() === $prescribedId) {
                    return $prescribed;
                }
            }
        }

        throw $this->createNotFoundException('Exercice introuvable dans cette séance.');
    }

    /** Champ numérique optionnel : « non renseigné » doit rester distinct de 0. */
    private function nullableInt(InputBag $payload, string $key): ?int
    {
        $raw = trim((string) $payload->get($key, ''));

        return '' === $raw ? null : (int) $raw;
    }

    private function nullableFloat(InputBag $payload, string $key): ?float
    {
        $raw = trim((string) $payload->get($key, ''));

        // La virgule décimale est ce qu'un clavier français produit naturellement.
        return '' === $raw ? null : (float) str_replace(',', '.', $raw);
    }

    /**
     * Déplace une séance planifiée sur une autre date (référence vivante : seule
     * la date change, la séance reste la même).
     */
    #[Route('/{id}/move', name: 'app_scheduled_workout_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $raw = $request->getPayload()->getString('scheduledDate');
        $newDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: null;

        if (null !== $newDate && $this->isCsrfTokenValid('move'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $scheduled->setScheduledDate($newDate);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance déplacée.');

            return $this->redirectAfterMutation($request, $scheduled, $newDate);
        }

        return $this->redirectAfterMutation($request, $scheduled, $scheduled->getScheduledDate());
    }

    /**
     * Boucle « prévu vs réalisé » (Phase 7) : marque une séance planifiée comme
     * faite / manquée / à nouveau prévue, avec une note d'écart léger optionnelle.
     * Pas de log détaillé de séries — Strava fait le suivi, ici on ne fait que
     * boucler sur la prévision.
     */
    #[Route('/{id}/status', name: 'app_scheduled_workout_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('status'.$scheduled->getId(), $payload->getString('_token'))) {
            $status = ScheduledStatus::tryFrom($payload->getString('status'));

            if (null !== $status) {
                $scheduled->setStatus($status);

                $notes = trim($payload->getString('completionNotes'));
                $scheduled->setCompletionNotes('' === $notes ? null : $notes);

                $this->entityManager->flush();

                // Réponse asynchrone : on re-rend le seul fragment concerné, la
                // page n'est pas rechargée. Repli sans JS = redirection classique.
                // Le fragment dépend de la page d'origine : un stream dont la
                // cible est absente du DOM ne fait rien, et `#cal-event-{id}`
                // n'existe pas sur la page de la séance datée.
                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    return 'schedule' === $payload->getString('return')
                        ? $this->streamScheduleStatus($request, $scheduled)
                        : $this->streamCalEvent($request, $scheduled, $planFlattener);
                }

                $this->addFlash('success', 'Statut mis à jour.');
            } else {
                $this->addFlash('error', 'Statut invalide.');
            }
        }

        return $this->redirectAfterMutation($request, $scheduled, $scheduled->getScheduledDate());
    }

    /**
     * Où retomber après une mutation. Par défaut le calendrier, dans la vue
     * mémorisée. Mais ces endpoints servent aussi la page de la séance datée :
     * elle poste `return=schedule` pour qu'on y revienne au lieu d'éjecter
     * l'utilisateur vers le planning à chaque changement de statut.
     */
    private function redirectAfterMutation(Request $request, ScheduledWorkout $scheduled, \DateTimeImmutable $date): Response
    {
        if ('schedule' === $request->getPayload()->getString('return')) {
            return $this->redirectToRoute('app_scheduled_workout_show', ['id' => $scheduled->getId()]);
        }

        return $this->redirectToMonth($date);
    }

    /**
     * Cycle rapide du statut (clic sur la zone gauche d'une pastille au
     * calendrier) : prévue → faite → manquée → prévue. Ne touche pas la note
     * d'écart (contrairement au formulaire complet de la modale) : c'est un
     * geste express, pas une saisie. Repli sans JS : c'est un vrai bouton de
     * formulaire, il fonctionne sans Stimulus.
     */
    #[Route('/{id}/cycle-status', name: 'app_scheduled_workout_cycle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cycleStatus(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        if ($this->isCsrfTokenValid('cycle'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $scheduled->setStatus($scheduled->getStatus()->next());
            $this->entityManager->flush();

            // Geste express : on re-rend la pastille en place (pas de rechargement,
            // la vue mois/semaine est préservée). Repli sans JS = redirection.
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                return $this->streamCalEvent($request, $scheduled, $planFlattener);
            }
        }

        return $this->redirectToMonth($scheduled->getScheduledDate());
    }

    /**
     * Re-rend en Turbo Stream les deux zones de `/schedule/{id}` qui portent le
     * statut : la pastille du hero et la section « Réalisé ». Pendant de
     * streamCalEvent() pour l'autre page appelante.
     */
    private function streamScheduleStatus(Request $request, ScheduledWorkout $scheduled): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('scheduled_workout/stream/status.stream.html.twig', [
            'scheduled' => $scheduled,
        ]);
    }

    /**
     * Re-rend la pastille d'une séance datée en Turbo Stream (action="replace"
     * sur `#cal-event-{id}`), à l'identique de son rendu d'origine. `detailed`
     * (vue semaine) est reporté par le formulaire ; `overdue` est recalculé.
     */
    private function streamCalEvent(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        $today = new \DateTimeImmutable('today');
        $overdue = ScheduledStatus::PLANNED === $scheduled->getStatus()
            && $scheduled->getScheduledDate() < $today;

        return $this->render('calendar/stream/cal_event.stream.html.twig', [
            'scheduled' => $scheduled,
            'fw' => $planFlattener->flattenWorkout($scheduled->getWorkout()),
            'statuses' => ScheduledStatus::cases(),
            'detailed' => (bool) $request->getPayload()->getInt('detailed'),
            'overdue' => $overdue,
        ]);
    }

    /**
     * Efface d'un coup un plan instancié : supprime TOUTES les séances datées
     * qui en proviennent (y compris DONE/MISSED — c'est une action explicite et
     * globale, distincte du retrait d'une case qui préserve le réalisé). Le
     * PlanTemplate n'est pas touché, seule son instanciation calendrier disparaît.
     * Permet notamment de vider un plan pour le ré-instancier sur une autre date.
     */
    #[Route('/plan/clear', name: 'app_scheduled_workout_clear_plan', methods: ['POST'])]
    public function clearPlan(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $repository,
    ): Response {
        $payload = $request->getPayload();
        $redirect = $this->monthFromPayload($payload);

        if (!$this->isCsrfTokenValid('clear_plan', $payload->getString('_token'))) {
            return $redirect;
        }

        $template = $planTemplateRepository->find($payload->getInt('planId'));
        if (null === $template) {
            $this->addFlash('error', 'Plan introuvable.');

            return $redirect;
        }

        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $scheduled = $repository->findBySourcePlanTemplateForOwner($template, $user);

        foreach ($scheduled as $one) {
            $this->entityManager->remove($one);
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Plan « %s » retiré du planning : %d séance(s) supprimée(s).',
            $template->getTitle(),
            \count($scheduled),
        ));

        return $redirect;
    }

    #[Route('/{id}/delete', name: 'app_scheduled_workout_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::DELETE, $scheduled);

        $date = $scheduled->getScheduledDate();

        if ($this->isCsrfTokenValid('delete'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($scheduled);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance retirée du planning.');
        }

        return $this->redirectToMonth($date);
    }

    /**
     * Redirige vers le calendrier positionné sur `$date`, dans la vue préférée de
     * l'utilisateur (cookie `kd_calview` posé par CalendarController). Ainsi une
     * mutation faite en vue semaine ré-atterrit en vue semaine (« résistance au
     * refresh »), sans se voir renvoyée en vue mois.
     */
    private function redirectToMonth(\DateTimeImmutable $date): Response
    {
        if ('week' === $this->preferredCalendarView()) {
            return $this->redirectToRoute('app_calendar_week', ['date' => $date->format('Y-m-d')]);
        }

        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
        ]);
    }

    private function redirectToCurrentMonth(): Response
    {
        // app_calendar_index respecte lui-même le cookie de vue.
        return $this->redirectToRoute('app_calendar_index');
    }

    /**
     * Redirige vers le mois de calendrier porté par le formulaire (champs cachés
     * year/month), avec repli sur le mois courant si absent ou invalide. Respecte
     * la vue préférée (semaine → semaine contenant le 1er du mois visé).
     */
    private function monthFromPayload(InputBag $payload): Response
    {
        $year = $payload->getInt('year');
        $month = $payload->getInt('month');

        if ($year >= 1 && $month >= 1 && $month <= 12) {
            return $this->redirectToMonth(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)));
        }

        return $this->redirectToCurrentMonth();
    }

    /** Vue calendrier mémorisée côté cookie ('week' | 'month', défaut 'month'). */
    private function preferredCalendarView(): string
    {
        return 'week' === $this->requestStack->getCurrentRequest()?->cookies->get('kd_calview')
            ? 'week' : 'month';
    }
}
