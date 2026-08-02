<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Http\ApiJson;
use App\Http\ApiProblem;
use App\Http\ScheduledWorkoutInput;
use App\Repository\ScheduledWorkoutRepository;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Service\LogIngestor;
use App\Service\ScheduledWorkoutPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * La séance datée, vue du téléphone : la lire (KL-15), l'écrire (KL-16), et
 * supprimer celles qu'il a créées lui-même.
 *
 * ## Tout passe par l'`uuid`, jamais par l'`id`
 *
 * Le client ne connaît pas les identifiants internes de ce qu'il a créé hors
 * réseau — il ne peut pas les connaître, il a créé la séance sans serveur. C'est
 * la même clé qui fait l'idempotence du `PUT` : rejouer une requête après une
 * coupure retombe sur la même ligne au lieu d'en créer une seconde.
 *
 * ## Les trois routes n'ont pas la même garde, et c'est le fond du ticket
 *
 * - `GET` teste **VIEW** : le coach lit le réalisé de son athlète, c'est son
 *   travail.
 * - `PUT` et `DELETE` testent **LOG** : écrire ou effacer le réalisé est réservé
 *   au propriétaire (KL-06). `EDIT` suffirait syntaxiquement et donnerait la main
 *   au coach — c'est exactement la confusion que cet attribut existe pour
 *   empêcher. Personne ne déclare à la place de quelqu'un d'autre ce qu'il a
 *   soulevé.
 *
 * ## Introuvable = 404, refusé = 403
 *
 * Contrairement à `GET /pairing/{id}/status` (KL-47) ou à la révocation d'un
 * appareil (KL-12), qui rendent 404 sur une ressource qui n'est pas la sienne, on
 * distingue ici les deux cas. La raison est que la clé n'est pas la même : là-bas
 * un identifiant séquentiel, qu'un tiers énumère en trois lignes de script ; ici
 * un UUID posé par le client, qu'on ne devine pas. Il n'y a donc pas d'oracle à
 * fermer, et un 403 dit au coach dont la relation vient d'être rompue ce qui lui
 * arrive, là où un 404 lui ferait croire à une séance disparue.
 */
final class ScheduleController extends AbstractController
{
    public function __construct(
        private readonly ScheduledWorkoutRepository $repository,
        private readonly ScheduledWorkoutPayload $payload,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Une séance datée, dans **la même structure** que celles du bootstrap
     * (KL-14) : c'est une exigence explicite du ticket, et la seule façon de la
     * tenir est de n'avoir qu'un producteur — `ScheduledWorkoutPayload`.
     *
     * Une séance sans `workout` (séance libre, ou source supprimée depuis) rend
     * un programme **vide**, pas une erreur : elle a du sens sans sa source
     * depuis que la clé étrangère est en `SET NULL` (KL-02).
     */
    #[Route('/api/schedule/{uuid}', name: 'api_schedule_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID])]
    public function show(Uuid $uuid): JsonResponse
    {
        $scheduled = $this->authorized($uuid, ScheduledWorkoutVoter::VIEW);

        return ApiJson::response($this->payload->build($scheduled));
    }

    /**
     * L'upsert : la séance est créée si l'`uuid` est inconnu, son réalisé
     * remplacé sinon.
     *
     * **201 à la création, 200 ensuite**, et c'est une information utile au
     * client hors réseau : rejouer sa file de mutations lui dit lesquelles
     * étaient déjà passées. Le corps est identique dans les deux cas — l'état
     * persisté, relu par le même producteur que le `GET`.
     *
     * La garde de propriété ne peut pas être posée avant de savoir si la séance
     * existe : on ne teste un voter que sur un sujet. Une séance **inconnue** est
     * donc créée pour le porteur du jeton, sans autre question ; une séance
     * connue exige `LOG`.
     */
    #[Route('/api/schedule/{uuid}', name: 'api_schedule_put', methods: ['PUT'], requirements: ['uuid' => Requirement::UUID])]
    public function put(
        Uuid $uuid,
        #[MapRequestPayload] ScheduledWorkoutInput $input,
        LogIngestor $ingestor,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $existing = $this->repository->findByUuidWithContentAndLog($uuid);

        if (null !== $existing) {
            $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::LOG, $existing);
        }

        $scheduled = $ingestor->upsert($user, $uuid, $input, $existing);

        return ApiJson::response(
            $this->payload->build($scheduled),
            null === $existing ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * Supprime une séance **libre**, et rien d'autre.
     *
     * Le téléphone crée des séances libres : il peut donc les défaire. Une séance
     * qui porte un programme a été posée sur le web — depuis la bibliothèque ou
     * par un plan — et c'est là qu'elle se retire, avec le contexte qui va avec
     * (retirer une case de plan, resynchroniser, préserver le réalisé). Rendre
     * 409 plutôt que 403 : ce n'est pas une question de droit, le propriétaire a
     * bien le droit ; c'est l'état de la ressource qui rend le geste impossible
     * ici.
     *
     * Le rattachement au plan est testé **en plus** de l'absence de programme :
     * une séance de plan dont la séance source a été supprimée en bibliothèque a
     * `workout = null` sans être libre pour autant.
     */
    #[Route('/api/schedule/{uuid}', name: 'api_schedule_delete', methods: ['DELETE'], requirements: ['uuid' => Requirement::UUID])]
    public function delete(Uuid $uuid): Response
    {
        $scheduled = $this->authorized($uuid, ScheduledWorkoutVoter::LOG);

        if (!self::isFreeform($scheduled)) {
            return ApiProblem::response(
                Response::HTTP_CONFLICT,
                'Cette séance vient de la bibliothèque ou d\'un plan : elle se retire depuis le web.',
            );
        }

        // Le `TombstoneListener` (KL-14) écrit la pierre tombale : les autres
        // appareils du compte apprendront la disparition à leur prochain delta.
        $this->em->remove($scheduled);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * La séance désignée, ou l'arrêt de la requête. 404 puis 403, dans cet
     * ordre : tester un voter sur rien n'a pas de sens.
     */
    private function authorized(Uuid $uuid, string $attribute): ScheduledWorkout
    {
        $scheduled = $this->repository->findByUuidWithContentAndLog($uuid);

        if (null === $scheduled) {
            throw $this->createNotFoundException('Séance datée introuvable.');
        }

        $this->denyAccessUnlessGranted($attribute, $scheduled);

        return $scheduled;
    }

    /** Sans programme ET sans plan : les deux, pour la raison dite au-dessus. */
    private static function isFreeform(ScheduledWorkout $scheduled): bool
    {
        return null === $scheduled->getWorkout()
            && null === $scheduled->getSourcePlanTemplate()
            && null === $scheduled->getSourcePlanItem();
    }
}
