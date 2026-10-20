<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Exercise;
use App\Entity\ExerciseSetup;
use App\Entity\User;
use App\Http\ApiJson;
use App\Http\ExerciseSetupInput;
use App\Repository\ExerciseRepository;
use App\Repository\ExerciseSetupRepository;
use App\Security\Voter\ExerciseVoter;
use App\Service\ExerciseSetupPayload;
use App\Service\PerformanceHistoryPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Un exercice vu du téléphone : sa trajectoire (`GET …/history`, KL-17) et les
 * réglages de machine qu'on y a notés (`PUT …/setup`, #8 mobile).
 *
 * ## La trajectoire
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
 * que d'un exercice qu'il a reçu au bootstrap, donc visible. La même règle vaut
 * pour le réglage, pour la même raison.
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

        return ApiJson::response($this->payload->build($user, $this->visible($id)));
    }

    /**
     * Écrit le réglage de machine du porteur du jeton sur cet exercice — le texte
     * entier, qui remplace le précédent. Vide, il supprime la ligne.
     *
     * **Un `PUT` rejouable** : la file de mutations du téléphone peut l'envoyer
     * deux fois après une coupure, et le second envoi retombe sur le même état.
     * Il n'y a qu'un écrivain par ligne — son auteur — donc pas de conflit à
     * trancher : la dernière écriture arrivée gagne, comme sur la note d'un
     * carnet.
     *
     * La garde est `VIEW`, pas `EDIT` : on ne modifie pas l'exercice, on écrit sa
     * propre ligne à côté. Un athlète règle la machine d'un exercice global, ou
     * de l'exercice maison que son coach lui a prescrit, sans avoir le droit de
     * toucher à l'un ou à l'autre.
     *
     * La réponse est toujours `200` avec l'état persisté, effacement compris
     * (`text: null`) : un seul cas à lire pour le client.
     */
    #[Route('/api/exercises/{id}/setup', name: 'api_exercise_setup', methods: ['PUT'], requirements: ['id' => Requirement::DIGITS])]
    public function setup(
        int $id,
        #[MapRequestPayload] ExerciseSetupInput $input,
        ExerciseSetupRepository $setups,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $exercise = $this->visible($id);
        $setup = $setups->findOneFor($user, $exercise);
        $text = $input->normalized();

        if (null === $text) {
            if (null !== $setup) {
                $em->remove($setup);
                $em->flush();
            }

            return ApiJson::response(ExerciseSetupPayload::entry($id, null, null));
        }

        if (null === $setup) {
            $setup = new ExerciseSetup($user, $exercise, $text);
            $em->persist($setup);
        } else {
            $setup->rewrite($text);
        }

        $em->flush();

        return ApiJson::response(ExerciseSetupPayload::entry($id, $setup->getText(), $setup->getUpdatedAt()));
    }

    /** L'exercice, s'il est lisible par le porteur du jeton. Sinon 404, dans les deux cas. */
    private function visible(int $id): Exercise
    {
        $exercise = $this->exercises->find($id);

        if (null === $exercise || !$this->isGranted(ExerciseVoter::VIEW, $exercise)) {
            throw $this->createNotFoundException('Exercice introuvable.');
        }

        return $exercise;
    }
}
