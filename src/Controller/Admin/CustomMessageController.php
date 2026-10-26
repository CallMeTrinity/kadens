<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CustomMessage;
use App\Form\CustomMessageType;
use App\Repository\CustomMessageRepository;
use App\Service\MessageMediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Le back-office des messages programmés. Gardé par `access_control`
 * (`^/admin` → ROLE_SUPER_ADMIN) : aucune action ici ne revérifie le rôle,
 * comme l'espace coach ne revérifie pas ROLE_COACH.
 */
#[Route('/admin/messages')]
final class CustomMessageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageMediaStorage $media,
    ) {
    }

    #[Route('', name: 'app_admin_message_index', methods: ['GET'])]
    public function index(CustomMessageRepository $messages): Response
    {
        return $this->render('admin/message/index.html.twig', [
            'messages' => $messages->findForAdmin(),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/new', name: 'app_admin_message_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');
        $message = (new CustomMessage())->setStartsOn($today)->setEndsOn($today);

        $form = $this->createForm(CustomMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->storeMedia($form, $message)) {
            $this->em->persist($message);
            $this->em->flush();
            $this->addFlash('success', 'Message programmé.');

            return $this->redirectToRoute('app_admin_message_edit', ['id' => $message->getId()]);
        }

        return $this->render('admin/message/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_message_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(Request $request, CustomMessage $message): Response
    {
        $form = $this->createForm(CustomMessageType::class, $message, ['has_media' => null !== $message->getMediaFile()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $previous = $message->getMediaFile();

            if ($form->has('removeMedia') && true === $form->get('removeMedia')->getData()) {
                $message->setMediaFile(null);
            }

            if ($this->storeMedia($form, $message)) {
                $this->em->flush();

                // Après l'écriture en base seulement : un échec plus haut
                // laisserait sinon le message pointer un fichier effacé.
                if (null !== $previous && $previous !== $message->getMediaFile()) {
                    $this->media->delete($previous);
                }

                $this->addFlash('success', 'Message mis à jour.');

                return $this->redirectToRoute('app_admin_message_edit', ['id' => $message->getId()]);
            }
        }

        return $this->render('admin/message/edit.html.twig', [
            'message' => $message,
            'form' => $form,
        ]);
    }

    /** Vide la liste des comptes qui l'ont vu : le message se remontre, pratique pour le tester sur soi. */
    #[Route('/{id}/reset-views', name: 'app_admin_message_reset_views', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function resetViews(Request $request, CustomMessage $message): Response
    {
        if (!$this->isCsrfTokenValid('reset_message_views'.$message->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $message->resetSeen();
        $this->em->flush();
        $this->addFlash('success', 'Vues remises à zéro : le message se montrera de nouveau.');

        return $this->redirectToRoute('app_admin_message_edit', ['id' => $message->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_admin_message_delete', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function delete(Request $request, CustomMessage $message): Response
    {
        if (!$this->isCsrfTokenValid('delete_message'.$message->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $file = $message->getMediaFile();
        $this->em->remove($message);
        $this->em->flush();

        if (null !== $file) {
            $this->media->delete($file);
        }

        $this->addFlash('success', 'Message supprimé.');

        return $this->redirectToRoute('app_admin_message_index');
    }

    /**
     * Range l'image envoyée, s'il y en a une, et la pose sur le message. Un
     * refus (format, poids) devient une erreur du champ et rend `false` : le
     * formulaire se réaffiche, rien n'est écrit.
     *
     * @param FormInterface<CustomMessage> $form
     */
    private function storeMedia(FormInterface $form, CustomMessage $message): bool
    {
        $upload = $form->get('media')->getData();
        if (!$upload instanceof UploadedFile) {
            return true;
        }

        try {
            $message->setMediaFile($this->media->store($upload));
        } catch (\InvalidArgumentException $e) {
            $form->get('media')->addError(new FormError($e->getMessage()));

            return false;
        }

        return true;
    }
}
