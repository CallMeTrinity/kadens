<?php

namespace App\Controller\Api;

use App\Http\ApiJson;
use App\Service\MobileRelease;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce que le serveur attend comme version d'app (KL-43).
 *
 * Deux nombres, et ils ne disent pas la même chose. `versionCode` est la
 * dernière version **publiée** : plus haute que la sienne, l'app propose une
 * mise à jour, et rien de plus — un bandeau qu'on peut ignorer pendant des mois,
 * parce qu'une séance ne se met pas en pause pour installer un APK.
 * `minimumVersionCode` est le **plancher** : en dessous, l'app se bloque
 * d'elle-même. C'est la seule porte de sortie prévue si le format de
 * synchronisation change, et elle ne monte qu'à cette occasion.
 *
 * **Le seul endpoint anonyme qui ne serve pas à obtenir un jeton**, et c'est
 * délibéré : le plancher doit se lire avant la connexion. Une app trop vieille
 * pour synchroniser doit le dire au lieu de laisser ouvrir une session qui ne
 * mènera qu'à des refus, et le jour où l'ancien format n'est plus servi, se
 * connecter est justement ce qui ne marchera plus.
 *
 * **Conséquence pour le client** : l'appeler **sans** en-tête `Authorization`
 * (`auth: false` côté mobile). L'authenticator se déclenche sur la seule présence
 * d'un `Bearer`, quelle que soit la route : un jeton périmé présenté ici ferait
 * échouer la requête avant ce contrôleur — exactement dans la situation où on a
 * le plus besoin de la réponse.
 */
final class AppVersionController extends AbstractController
{
    #[Route('/api/app-version', name: 'api_app_version', methods: ['GET'])]
    public function __invoke(MobileRelease $release): JsonResponse
    {
        return ApiJson::response([
            'versionCode' => $release->versionCode(),
            'versionName' => $release->versionName(),
            'minimumVersionCode' => $release->minimumVersionCode(),
            // Nuls tant qu'aucune version n'est publiée : le client n'a alors
            // rien à proposer, et un lien vers une release inexistante serait
            // pire qu'un lien absent.
            'apkUrl' => $release->apkUrl(),
            'storeUrl' => $release->storeUrl(),
            // La page d'installation, absolue : c'est ce que l'app ouvre depuis
            // son bandeau, et elle explique le dépôt avant de donner l'APK.
            'installUrl' => $this->generateUrl('app_install', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }
}
