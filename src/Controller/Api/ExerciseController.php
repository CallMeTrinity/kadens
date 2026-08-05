<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ApiJson;
use App\Repository\ExerciseRepository;
use App\Security\Voter\ExerciseVoter;
use App\Service\PerformanceHistoryPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * `GET /api/exercises/{id}/history` — la trajectoire d'un exercice (KL-17).
 *
 * ## Ce que cet endpoint ajoute au bootstrap
 *
 * Le bootstrap (KL-14) descend déjà, pour toute la bibliothèque, la **dernière**
 * performance et le record : c'est ce que le téléphone affiche sous chaque
 * exercice en séance, hors réseau (KL-32). Il ne descend pas plus, et il ne le
 * doit pas — l'historique complet de tous les exercices ferait grossir une
 * réponse qu'on a bornée à 1 Mo pour un écran qu'on ouvre rarement.
 *
 * Cet endpoint est cet écran-là : les dix dernières séances sur **un** exercice,
 * demandées quand on les regarde, donc en ligne. C'est la seule chose de l'app
 * mobile qui suppose du réseau, et c'est assumé : consulter une progression n'est
 * pas dérouler une séance.
 *
 * ## Introuvable et invisible rendent le même 404
 *
 * Contrairement à `GET /api/schedule/{uuid}` (KL-15), qui distingue 404 et 403,
 * on ne distingue pas ici — et c'est la même règle appliquée, pas son contraire :
 * ce qui décide, c'est la **nature de la clé**. Un `uuid` posé par le client ne se
 * devine pas, il n'y a pas d'oracle à fermer ; un identifiant séquentiel
 * d'exercice s'énumère en trois lignes de script, et un 403 y dirait « cet
 * identifiant existe, il appartient à quelqu'un d'autre » — soit la taille et la
 * composition de la bibliothèque perso des autres, exercice par exercice.
 *
 * La distinction ne manquerait à personne : le téléphone ne demande l'historique
 * que d'un exercice qu'il a reçu au bootstrap, donc visible.
 */
final class ExerciseController extends AbstractController
{
    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly PerformanceHistoryPayload $payload,
    ) {
    }

    /**
     * L'historique est **scopé au porteur du jeton**, jamais à l'exercice seul.
     * Un exercice de la bibliothèque globale est pratiqué par tout le monde ;
     * sa fiche est publique, ce qu'on y a soulevé ne l'est pas. La garde est dans
     * `PerformanceHistory` (KL-04), qui ne lit que le réalisé de l'utilisateur
     * demandé — un coach qui ouvre cette fiche voit donc *sa* trajectoire, pas
     * celle de son athlète.
     */
    #[Route('/api/exercises/{id}/history', name: 'api_exercise_history', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function history(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $exercise = $this->exercises->find($id);

        if (null === $exercise || !$this->isGranted(ExerciseVoter::VIEW, $exercise)) {
            throw $this->createNotFoundException('Exercice introuvable.');
        }

        return ApiJson::response($this->payload->build($user, $exercise));
    }
}
