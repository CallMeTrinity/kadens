<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\GoalBlock;
use App\Entity\User;
use App\Enum\GoalBlockType;
use App\Repository\ImportedActivityRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Security\Voter\GoalVoter;
use App\Service\GoalPhotoStorage;
use App\Service\GoalStory;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le récit d'un objectif (#42) : l'éditeur de blocs, le service des photos et
 * la publication.
 *
 * Les droits suivent GoalVoter — le coach accepté est co-éditeur du récit comme
 * du reste de l'objectif — avec **une exception : publier.** Rendre un récit
 * public expose le contenu de l'athlète, ses photos comprises, à quiconque a le
 * lien. C'est une décision de propriétaire, comme le bloc-notes privé est une
 * lecture de propriétaire : `publish` refuse le coach en 403, l'attribut EDIT de
 * la route ne suffit pas.
 *
 * Tout passe par des formulaires classiques et une redirection vers l'éditeur,
 * ancrée sur le bloc touché : sans JS, l'éditeur reste entièrement utilisable.
 */
#[Route('/goal/{id}', requirements: ['id' => '\d+'])]
final class GoalStoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/story', name: 'app_goal_story', methods: ['GET'])]
    public function edit(Goal $goal, GoalStory $story): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);

        return $this->render('goal/story.html.twig', [
            'goal' => $goal,
            'story' => $story->present($goal),
            'candidates' => $story->candidates($goal),
        ]);
    }

    /**
     * Ajoute un bloc en fin de récit. Le type décide de ce qu'on lit dans la
     * requête ; une saisie invalide ne crée rien et le dit.
     */
    #[Route('/story/add', name: 'app_goal_story_add', methods: ['POST'])]
    public function add(
        Request $request,
        Goal $goal,
        GoalStory $story,
        GoalPhotoStorage $photos,
        ImportedActivityRepository $activities,
        ScheduledWorkoutRepository $scheduledWorkouts,
    ): Response {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);
        $this->assertCsrf($request, $goal);

        $type = GoalBlockType::tryFrom($request->getPayload()->getString('type'));
        if (null === $type) {
            throw $this->createNotFoundException();
        }

        $body = $this->body($request);
        $block = (new GoalBlock())->setType($type)->setBody($body);

        switch ($type) {
            case GoalBlockType::TEXT:
                if (null === $body) {
                    return $this->fail($goal, 'Le texte est vide.');
                }
                break;

            case GoalBlockType::PHOTO:
                $upload = $request->files->get('photo');
                if (!$upload instanceof UploadedFile) {
                    return $this->fail($goal, 'Choisis une photo à ajouter.');
                }
                try {
                    $stored = $photos->store($upload);
                } catch (\InvalidArgumentException $e) {
                    return $this->fail($goal, $e->getMessage());
                }
                $block->setPhotoFile($stored['file'])->setPhotoSize($stored['width'], $stored['height']);
                break;

            case GoalBlockType::ACTIVITY:
                $activity = $activities->find($request->getPayload()->getInt('importedActivity'));
                // Une activité d'un autre compte rend 404, comme au rattachement :
                // un refus ne confirme pas qu'un identifiant existe.
                if (null === $activity || !$story->acceptsActivity($goal, $activity)) {
                    throw $this->createNotFoundException();
                }
                if ($goal->hasReferenceTo($activity)) {
                    return $this->fail($goal, 'Cette activité est déjà dans le récit.');
                }
                $block->setImportedActivity($activity);
                break;

            case GoalBlockType::SESSION:
                $scheduled = $scheduledWorkouts->find($request->getPayload()->getInt('scheduledWorkout'));
                if (null === $scheduled || !$story->acceptsSession($goal, $scheduled)) {
                    throw $this->createNotFoundException();
                }
                if ($goal->hasReferenceTo($scheduled)) {
                    return $this->fail($goal, 'Cette séance est déjà dans le récit.');
                }
                $block->setScheduledWorkout($scheduled);
                break;
        }

        $goal->appendBlock($block);
        $this->entityManager->persist($block);
        $this->entityManager->flush();

        return $this->backToBlock($goal, $block);
    }

    /** Réécrit le texte d'un bloc (le texte lui-même, ou la légende / le commentaire). */
    #[Route('/story/{blockId}/update', name: 'app_goal_story_update', methods: ['POST'], requirements: ['blockId' => '\d+'])]
    public function update(Request $request, Goal $goal, #[MapEntity(id: 'blockId')] GoalBlock $block): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);
        $this->assertBelongs($goal, $block);
        $this->assertCsrf($request, $goal);

        $body = $this->body($request);
        if (GoalBlockType::TEXT === $block->getType() && null === $body) {
            return $this->fail($goal, 'Un bloc de texte ne peut pas être vide : supprime-le plutôt.');
        }

        $block->setBody($body);
        $this->entityManager->flush();
        $this->addFlash('success', 'Bloc enregistré.');

        return $this->backToBlock($goal, $block);
    }

    #[Route('/story/{blockId}/move', name: 'app_goal_story_move', methods: ['POST'], requirements: ['blockId' => '\d+'])]
    public function move(Request $request, Goal $goal, #[MapEntity(id: 'blockId')] GoalBlock $block): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);
        $this->assertBelongs($goal, $block);
        $this->assertCsrf($request, $goal);

        $goal->moveBlock($block, 'up' === $request->getPayload()->getString('direction') ? -1 : 1);
        $this->entityManager->flush();

        return $this->backToBlock($goal, $block);
    }

    /** La photo d'un bloc supprimé part du disque après le flush (GoalPhotoCleanupListener). */
    #[Route('/story/{blockId}/delete', name: 'app_goal_story_delete', methods: ['POST'], requirements: ['blockId' => '\d+'])]
    public function delete(Request $request, Goal $goal, #[MapEntity(id: 'blockId')] GoalBlock $block): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);
        $this->assertBelongs($goal, $block);
        $this->assertCsrf($request, $goal);

        $goal->removeBlock($block);
        $this->entityManager->remove($block);
        $this->entityManager->flush();
        $this->addFlash('success', 'Bloc supprimé.');

        return $this->redirectToRoute('app_goal_story', ['id' => $goal->getId()]);
    }

    /**
     * Sert une photo du récit à qui peut lire l'objectif. Cache **privé** : la
     * réponse dépend de la session, aucun intermédiaire ne doit la garder. Le nom
     * de fichier est aléatoire et ne change jamais pour un bloc donné, d'où une
     * longue durée.
     */
    #[Route('/photo/{blockId}', name: 'app_goal_photo', methods: ['GET'], requirements: ['blockId' => '\d+'])]
    public function photo(Goal $goal, #[MapEntity(id: 'blockId')] GoalBlock $block, GoalPhotoStorage $photos): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::VIEW, $goal);
        $this->assertBelongs($goal, $block);

        $response = $photos->response($block);
        if (null === $response) {
            throw $this->createNotFoundException();
        }
        $response->setPrivate();

        return $response;
    }

    /**
     * Publier / dépublier. Le slug se pose à la première publication, dérivé de
     * l'intitulé, et reste ensuite : dépublier puis republier redonne le même
     * lien, et renommer l'objectif ne casse pas un lien déjà envoyé.
     */
    #[Route('/publish', name: 'app_goal_publish', methods: ['POST'])]
    public function publish(Request $request, Goal $goal, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);

        /** @var User $user */
        $user = $this->getUser();
        if ($goal->getOwner()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Seul le propriétaire publie son récit.');
        }

        if (!$this->isCsrfTokenValid('goal_publish'.$goal->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ('unpublish' === $request->getPayload()->getString('action')) {
            $goal->setPublishedAt(null);
            $this->addFlash('success', 'Récit dépublié : le lien public ne répond plus.');
        } else {
            if (null === $goal->getSlug()) {
                $goal->setSlug($slugGenerator->generate((string) $goal->getTitle(), Goal::class));
            }
            $goal->setPublishedAt($goal->getPublishedAt() ?? new \DateTimeImmutable());
            $this->addFlash('success', 'Récit publié. Copie le lien pour le partager.');
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
    }

    private function body(Request $request): ?string
    {
        $body = trim($request->getPayload()->getString('body'));

        return '' === $body ? null : $body;
    }

    private function assertCsrf(Request $request, Goal $goal): void
    {
        if (!$this->isCsrfTokenValid('goal_story'.$goal->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /** Un bloc d'un autre objectif rend 404 : l'URL mélange deux identifiants. */
    private function assertBelongs(Goal $goal, GoalBlock $block): void
    {
        if ($block->getGoal()?->getId() !== $goal->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function fail(Goal $goal, string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_goal_story', ['id' => $goal->getId()]);
    }

    private function backToBlock(Goal $goal, GoalBlock $block): Response
    {
        return $this->redirect($this->generateUrl('app_goal_story', ['id' => $goal->getId()]).'#block-'.$block->getId());
    }
}
