<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomMessage;
use App\Entity\User;
use App\Http\LocalPath;
use App\Repository\CustomMessageRepository;
use App\Service\MessageMediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Le côté destinataire des messages programmés : fermer la modale (« vu ») et
 * lire l'image. L'écriture des messages vit dans `Admin\CustomMessageController`.
 */
final class MessageController extends AbstractController
{
    public function __construct(
        private readonly CustomMessageRepository $messages,
    ) {
    }

    /**
     * « Fermer » : le message ne revient plus, sur aucun appareil. Calqué sur
     * le refus de l'invitation à choisir un pseudo (CSRF, `_back`).
     *
     * Un message qui ne s'adresse pas à ce compte rend 404, comme un message
     * inconnu : l'identifiant est séquentiel, un 403 dirait qu'il existe.
     */
    #[Route('/message/{id}/seen', name: 'app_message_seen', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function seen(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('message_seen'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $message = $this->messages->find($id);
        if (!$message instanceof CustomMessage || !$message->isAddressedTo($user)) {
            throw $this->createNotFoundException('Message introuvable.');
        }

        $message->markSeenBy($user);
        $em->flush();

        return $this->redirect(LocalPath::orRoot($request->getPayload()->getString('_back')));
    }

    /**
     * L'image d'un message, hors pare-feu applicatif (`PUBLIC_ACCESS`) : c'est
     * la signature qui fait office de droit de lecture (cf. `CustomMessageInbox`).
     * Le nom de fichier doit être celui du message **aujourd'hui** : une
     * ancienne URL d'une image remplacée ne sert plus rien.
     */
    #[Route('/media/message/{id}/{file}', name: 'app_message_media', methods: ['GET'], requirements: ['id' => Requirement::DIGITS, 'file' => '[a-f0-9]{32}\.(gif|jpg|png|webp)'])]
    public function media(
        int $id,
        string $file,
        Request $request,
        MessageMediaStorage $storage,
        #[Autowire(service: 'uri_signer')]
        UriSigner $signer,
    ): Response {
        if (!$signer->checkRequest($request)) {
            throw $this->createNotFoundException('Image introuvable.');
        }

        $message = $this->messages->find($id);
        if (!$message instanceof CustomMessage || $message->getMediaFile() !== $file) {
            throw $this->createNotFoundException('Image introuvable.');
        }

        return $storage->response($file) ?? throw $this->createNotFoundException('Image introuvable.');
    }
}
