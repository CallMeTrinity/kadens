<?php

namespace App\Controller;

use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\UserRepository;
use App\Service\IcsCalendarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Flux iCalendar (ICS) pour l'abonnement à un calendrier externe (Google Agenda,
 * Apple Calendar…). Récupéré par le serveur du client SANS session : l'accès n'est
 * donc PAS régi par l'authentification ni les voters, mais par un jeton secret
 * porté par l'URL — même philosophie que le partage public par slug. Tout reste
 * sous le préfixe `/feed`, volontairement hors `access_control`.
 *
 * Deux portées, choisies par l'URL : tout le calendrier, ou un plan instancié
 * précis (borné à l'owner du jeton).
 */
final class PublicCalendarController extends AbstractController
{
    // Le jeton fait 64 hex (32 octets) ; la borne empêche aussi le point de `.ics`
    // d'être avalé par le paramètre.
    private const TOKEN = '[A-Za-z0-9]{16,64}';

    #[Route('/feed/{token}.ics', name: 'app_calendar_feed', methods: ['GET'], requirements: ['token' => self::TOKEN])]
    public function all(
        string $token,
        UserRepository $userRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        IcsCalendarBuilder $builder,
    ): Response {
        $user = $this->requireUserByToken($token, $userRepository);

        $ics = $builder->build(
            $scheduledWorkoutRepository->findAllForOwnerWithContent($user),
            'Kadens — Planning',
        );

        return $this->icsResponse($ics);
    }

    #[Route('/feed/{token}/plan/{planId}.ics', name: 'app_calendar_feed_plan', methods: ['GET'], requirements: ['token' => self::TOKEN, 'planId' => '\d+'])]
    public function plan(
        string $token,
        int $planId,
        UserRepository $userRepository,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        IcsCalendarBuilder $builder,
    ): Response {
        $user = $this->requireUserByToken($token, $userRepository);

        $plan = $planTemplateRepository->find($planId);
        if (null === $plan || $plan->getOwner() !== $user) {
            throw $this->createNotFoundException('Plan introuvable.');
        }

        $ics = $builder->build(
            $scheduledWorkoutRepository->findBySourcePlanTemplateForOwnerWithContent($plan, $user),
            'Kadens — '.$plan->getTitle(),
        );

        return $this->icsResponse($ics);
    }

    private function requireUserByToken(string $token, UserRepository $userRepository): \App\Entity\User
    {
        $user = $userRepository->findOneBy(['calendarFeedToken' => $token]);
        if (null === $user) {
            throw $this->createNotFoundException('Lien d\'abonnement invalide ou révoqué.');
        }

        return $user;
    }

    private function icsResponse(string $ics): Response
    {
        $response = new Response($ics);
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->headers->set('Content-Disposition', 'inline; filename="kadens.ics"');

        return $response;
    }
}
