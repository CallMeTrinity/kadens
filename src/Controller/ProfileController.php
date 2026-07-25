<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileType;
use App\Service\HeartRateZones;
use App\Service\ProfileStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page profil : remplace l'ancienne page d'accueil ET la synthèse. Combine les
 * stats générales de l'athlète (ProfileStats) et sa fiche éditable (ProfileType).
 * Le profil est la page d'accueil (« / »).
 */
final class ProfileController extends AbstractController
{
    /**
     * Page d'accueil = profil. `/` n'est pas couvert par access_control : on garde
     * manuellement (comme l'ancien HomeController).
     */
    #[Route('/', name: 'app_profile', methods: ['GET'])]
    public function index(ProfileStats $profileStats, HeartRateZones $heartRateZones): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('profile/index.html.twig', [
            'stats' => $profileStats->for($user),
            'hrZones' => $heartRateZones->forUser($user),
        ]);
    }

    /**
     * Édition de la fiche athlète. Sous `^/profile` (protégé par access_control).
     */
    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Fiche athlète mise à jour.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
