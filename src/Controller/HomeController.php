<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CoachingRepository;
use App\Repository\GoalRepository;
use App\Service\StatsPeriod;
use App\Service\TrainingStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'accueil : « où j'en suis ». Le prochain objectif, l'observance et la
 * répartition du mois, une demande de coaching en attente, et des raccourcis
 * vers les pages de lecture. La fiche de l'athlète (records, mesures, stats
 * depuis le début) vit sur `/profile`.
 *
 * Le mois se lit sur `TrainingStats`, le moteur unique : les chiffres de
 * l'accueil sont ceux de `/profile/stats?range=<mois>`, sur lequel la carte
 * renvoie.
 */
final class HomeController extends AbstractController
{
    /** Le prochain objectif en aplat, et celui d'après en pied de carte. */
    private const UPCOMING_GOALS = 2;

    /**
     * `/` n'est pas couvert par access_control : on garde manuellement.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        TrainingStats $training,
        GoalRepository $goalRepository,
        CoachingRepository $coachingRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $today = new \DateTimeImmutable('today');
        $month = $training->over($user, StatsPeriod::month((int) $today->format('Y'), (int) $today->format('n')));

        return $this->render('home/index.html.twig', [
            'today' => $today,
            'month' => $month,
            'goals' => $goalRepository->findUpcomingForOwner($user, self::UPCOMING_GOALS),
            // Découvrabilité : une demande reçue doit se voir dès l'arrivée, pas
            // seulement dans /coaching. Les relations actives, elles, n'appellent
            // aucune action : elles restent dans /coaching.
            'coachingReceived' => $coachingRepository->findPendingReceivedBy($user),
        ]);
    }
}
