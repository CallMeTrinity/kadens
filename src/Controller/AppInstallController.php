<?php

namespace App\Controller;

use App\Service\MobileRelease;
use App\Service\QrSvg;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La page d'installation de l'app Android (KL-43).
 *
 * **Anonyme**, comme le partage public (`/s/…`) : `/app` n'est couvert par aucune
 * règle d'`access_control`, et c'est voulu. On y arrive depuis un téléphone qui
 * n'a pas encore l'app, donc pas de session — demander de se connecter pour
 * apprendre comment installer serait un tourniquet.
 *
 * Elle est **auto-suffisante** : tout ce qu'elle affiche est rendu côté serveur,
 * QR compris (`QrSvg`). Aucune requête après chargement, aucune bibliothèque
 * JavaScript, donc rien qui casse sur un réseau de salle — c'est la même règle
 * que pour les pages de consultation.
 */
final class AppInstallController extends AbstractController
{
    #[Route('/app', name: 'app_install', methods: ['GET'])]
    public function __invoke(MobileRelease $release, QrSvg $qr): Response
    {
        return $this->render('app/install.html.twig', [
            'release' => $release,
            // Le QR encode **l'URL du dépôt telle quelle** : c'est ce que le
            // store attend, et c'est aussi ce qui s'affiche juste à côté en clair
            // pour qui préfère la recopier. Une seule source, deux façons de la
            // donner à lire.
            'storeQr' => $qr->render($release->storeUrl()),
        ]);
    }
}
