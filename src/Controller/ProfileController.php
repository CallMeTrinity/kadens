<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\PairingCode;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ProfileType;
use App\Repository\ApiTokenRepository;
use App\Repository\CoachingRepository;
use App\Repository\GoalRepository;
use App\Repository\PairingCodeRepository;
use App\Service\HeartRateZones;
use App\Service\PairingQr;
use App\Service\ProfileStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Page profil : remplace l'ancienne page d'accueil ET la synthèse. Combine les
 * stats générales de l'athlète (ProfileStats) et sa fiche éditable (ProfileType).
 * Le profil est la page d'accueil (« / »).
 */
final class ProfileController extends AbstractController
{
    /**
     * Injecté au constructeur et non par action : trois points de rendu de
     * `/profile/settings` doivent servir la liste des appareils (l'affichage, le
     * repli sans JS de l'émission d'un code, et les deux révocations), et une
     * page qui oublierait de la passer casserait en `strict_variables`.
     */
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
    ) {
    }

    /**
     * Page d'accueil = profil. `/` n'est pas couvert par access_control : on garde
     * manuellement (comme l'ancien HomeController).
     */
    #[Route('/', name: 'app_profile', methods: ['GET'])]
    public function index(
        ProfileStats $profileStats,
        HeartRateZones $heartRateZones,
        GoalRepository $goalRepository,
        CoachingRepository $coachingRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('profile/index.html.twig', [
            'stats' => $profileStats->for($user),
            'hrZones' => $heartRateZones->forUser($user),
            'upcomingGoals' => $goalRepository->findUpcomingForOwner($user, 3),
            // Découvrabilité : une demande de coaching reçue doit se voir depuis
            // la page d'accueil, pas seulement dans /coaching.
            'coachingReceived' => $coachingRepository->findPendingReceivedBy($user),
            'coachingActive' => $coachingRepository->findAcceptedCoaches($user),
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

    /**
     * Paramètres du compte : identifiants et sécurité (le contenu sportif reste
     * sur la fiche athlète). Sous `^/profile`, donc protégé par access_control.
     */
    #[Route('/profile/settings', name: 'app_profile_settings', methods: ['GET', 'POST'])]
    public function settings(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();

            // Refus du « changement » qui n'en est pas un : sans ça, le
            // formulaire répondrait « mot de passe mis à jour » sans rien changer.
            if ($passwordHasher->isPasswordValid($user, $plainPassword)) {
                $form->get('plainPassword')->addError(
                    new FormError('Le nouveau mot de passe doit être différent de l\'actuel.')
                );
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                $entityManager->flush();

                // Le hash fait partie du token stocké en session : sans
                // ré-authentification, la requête suivante verrait un utilisateur
                // « changé » et déconnecterait l'auteur du changement.
                $security->login($user);

                $this->addFlash('success', 'Mot de passe mis à jour.');

                return $this->redirectToRoute('app_profile_settings');
            }
        }

        return $this->render('profile/settings.html.twig', [
            'form' => $form,
            'devices' => $this->apiTokens->findForOwner($user),
        ]);
    }

    /**
     * Émet un code d'appairage et rend le panneau qui l'affiche (§0.6, KL-47).
     * C'est le seul chemin qui en crée : un code est **lié à la session desktop
     * qui l'a produit**, donc à un utilisateur déjà authentifié — c'est ce qui
     * interdit de s'appairer au compte d'un autre.
     *
     * Sous le pare-feu `main`, et hors `^/profile` : `security.yaml` couvre
     * `^/pairing` explicitement. Le CSRF est vérifié à la main comme partout
     * ailleurs dans le projet, la requête ne passant pas par un `FormType`.
     *
     * **Une écriture, donc un POST — et pas de redirection après.** Le code en
     * clair n'existe que dans la réponse qui l'émet et sur l'écran qui l'affiche
     * (la base n'en a que l'empreinte) : rediriger obligerait à le faire vivre
     * ailleurs, en session, c'est-à-dire à créer un second endroit où un secret
     * de deux minutes traîne. Le repli sans JS rend donc la page entière en
     * réponse au POST ; avec Turbo, seul le panneau est remplacé.
     */
    #[Route('/pairing/code', name: 'app_pairing_code', methods: ['POST'])]
    public function pairingCode(
        Request $request,
        PairingCodeRepository $pairingCodes,
        EntityManagerInterface $entityManager,
        PairingQr $pairingQr,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('pairing_code', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // Un écran, un code : régénérer invalide le précédent, qui resterait
        // sinon échangeable deux minutes sur un poste qu'on vient de quitter.
        $pairingCodes->deleteUnusedFor($user);

        $code = PairingCode::generateCode();
        $pairingCode = new PairingCode($user, $code);

        $entityManager->persist($pairingCode);
        $entityManager->flush();

        $payload = $pairingQr->payload(
            $pairingCode,
            $code,
            $request->getSchemeAndHttpHost().$request->getBaseUrl(),
        );

        $context = [
            'pairing' => $pairingCode,
            'pairingCode' => $code,
            'pairingQr' => $pairingQr->svg($payload),
        ];

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('profile/stream/pairing.stream.html.twig', $context);
        }

        return $this->render('profile/settings.html.twig', $context + [
            'form' => $this->createForm(ChangePasswordType::class)->createView(),
            'devices' => $this->apiTokens->findForOwner($user),
        ]);
    }

    /**
     * L'état d'un code émis, pour la confirmation visuelle du desktop : « ce
     * téléphone-là vient de se connecter ». C'est ce que le snapshot
     * `consumedByDevice` de KL-46 existait pour permettre.
     *
     * Interrogée en boucle par le contrôleur Stimulus `pairing`, mais **bornée
     * par nature** : elle n'a plus rien à dire dès que le code est consommé ou
     * échu, et la fenêtre entière dure deux minutes. Ce n'est donc pas l'AJAX
     * post-chargement que le projet refuse sur ses pages de consultation — il
     * n'y a rien à mettre en cache offline dans un secret qui périme.
     *
     * La garde est le propriétaire, et un code qui n'est pas le sien rend
     * **404** : un 403 confirmerait qu'il existe.
     */
    #[Route('/pairing/{id}/status', name: 'app_pairing_status', methods: ['GET'])]
    public function pairingStatus(PairingCode $pairingCode): JsonResponse
    {
        if ($pairingCode->getOwner() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'used' => $pairingCode->isUsed(),
            // Le nom vient du téléphone : il est rendu tel quel dans du texte,
            // jamais dans du HTML assemblé côté client.
            'device' => $pairingCode->getConsumedByDevice(),
            'expired' => $pairingCode->isExpired(),
        ]);
    }

    /**
     * Révoque un appareil (KL-12). Sans ce geste, l'appairage n'est pas
     * réversible : un jeton dont l'échéance glisse à chaque usage ne s'éteint
     * jamais tout seul tant que le téléphone qui le porte s'en sert.
     *
     * **Révoquer, c'est supprimer la ligne**, comme `POST /api/auth/logout`
     * (KL-11). Un jeton qu'on marquerait « révoqué » demanderait à toutes les
     * lectures de s'en souvenir ; l'absence, elle, ne s'oublie pas.
     *
     * Un jeton qui n'est pas le sien rend **404 et non 403** : même raisonnement
     * que l'état d'un code d'appairage — distinguer « pas à toi » de « n'existe
     * pas » confirmerait l'existence à qui essaie des identifiants.
     */
    #[Route('/profile/devices/{id}/revoke', name: 'app_profile_device_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revokeDevice(
        Request $request,
        ApiToken $apiToken,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($apiToken->getOwner() !== $user) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('revoke_device'.$apiToken->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $entityManager->remove($apiToken);
        $entityManager->flush();

        return $this->afterRevocation($request, $user, 'Appareil révoqué.');
    }

    /**
     * « Tout révoquer » : le geste qu'on fait quand on ne sait plus ce qui est
     * connecté. Il ne touche **que les jetons**, pas les codes d'appairage non
     * consommés : un code n'est pas un accès mais une invitation de deux minutes,
     * affichée sur l'écran de celui-là même qui révoque — il ne peut pas être
     * parti avec le téléphone perdu.
     */
    #[Route('/profile/devices/revoke-all', name: 'app_profile_devices_revoke_all', methods: ['POST'])]
    public function revokeAllDevices(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('revoke_devices', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->apiTokens->deleteForOwner($user);

        return $this->afterRevocation($request, $user, 'Tous les appareils ont été révoqués.');
    }

    /**
     * La réponse commune aux deux révocations : le panneau des appareils, re-rendu
     * en place. Repli sans JS = redirection avec un message.
     *
     * Un stream **plutôt qu'une redirection systématique** pour la même raison
     * qu'en KL-47 : la page porte trois choses indépendantes (un QR affiché, une
     * saisie de mot de passe, cette liste), et révoquer un vieil appareil pendant
     * qu'on en appaire un nouveau est un geste normal — il n'a pas à effacer le
     * code à l'écran.
     *
     * **Pas de flash dans la branche stream** : il n'y a pas de rechargement pour
     * l'afficher, il resterait en session et surgirait à la navigation suivante.
     * La ligne qui disparaît est la confirmation.
     */
    private function afterRevocation(Request $request, User $user, string $message): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('profile/stream/devices.stream.html.twig', [
                'devices' => $this->apiTokens->findForOwner($user),
            ]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_profile_settings');
    }
}
