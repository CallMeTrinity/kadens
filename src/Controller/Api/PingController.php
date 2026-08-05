<?php

namespace App\Controller\Api;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La sonde de l'API : le serveur répond, et le jeton présenté est encore valide.
 *
 * Elle sert au client mobile après un appairage (le QR porte l'URL du serveur,
 * §0.6 : il faut pouvoir vérifier qu'elle mène bien à un Kadens) et avant une
 * synchronisation. Elle est **authentifiée** : une sonde qu'on peut appeler sans
 * jeton dirait que le serveur répond, pas qu'on y a encore accès.
 *
 * Volontairement muette sur l'identité — c'est `GET /api/me` (KL-11) qui la
 * porte, avec les rôles et le dernier bootstrap.
 */
final class PingController extends AbstractController
{
    #[Route('/api/ping', name: 'api_ping', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'ok' => true,
            'user' => $user->getUserIdentifier(),
        ]);
    }
}
