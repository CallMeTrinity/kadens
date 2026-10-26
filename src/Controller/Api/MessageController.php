<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\CustomMessage;
use App\Entity\User;
use App\Repository\CustomMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Un message programmé vu sur le téléphone. Le pendant de `POST /message/{id}/seen`
 * côté web : la mémoire du « vu » est la même, fermer la modale d'un côté la
 * retire de l'autre.
 */
final class MessageController extends AbstractController
{
    /**
     * **Un `PUT` rejouable** : la file de mutations du téléphone peut l'envoyer
     * deux fois après une coupure, le second envoi retombe sur le même état.
     * Pas de corps, `204`.
     *
     * Un message qui ne s'adresse pas au porteur du jeton rend 404, comme un
     * inconnu : l'identifiant est séquentiel, un 403 dirait qu'il existe. Les
     * dates et l'état actif, eux, n'entrent pas en compte : un message vu la
     * veille de sa désactivation reste vu.
     */
    #[Route('/api/messages/{id}/seen', name: 'api_message_seen', methods: ['PUT'], requirements: ['id' => Requirement::DIGITS])]
    public function seen(int $id, CustomMessageRepository $messages, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $message = $messages->find($id);
        if (!$message instanceof CustomMessage || !$message->isAddressedTo($user)) {
            throw $this->createNotFoundException('Message introuvable.');
        }

        $message->markSeenBy($user);
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
