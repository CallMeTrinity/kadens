<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Security\ApiTokenAuthenticator;
use App\Service\BootstrapPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/bootstrap` — tout ce dont le téléphone a besoin pour travailler hors
 * réseau, en une requête (KL-14).
 *
 * Le contrôleur est mince à dessein : il valide `since`, délègue à
 * `BootstrapPayload` (qui porte les décisions de portée et de delta), et note le
 * passage sur le jeton. Trois choses valent d'être dites ici :
 *
 * - **Aucun voter.** Toutes les lectures sont scopées sur l'utilisateur du jeton,
 *   il n'y a pas de ressource désignée par la requête donc rien à autoriser au
 *   cas par cas. Un `since` mal formé est la seule chose que le client puisse
 *   faire de travers.
 * - **`lastBootstrapAt` s'écrit APRÈS la construction de la charge utile**, et
 *   c'est le seul endroit qui l'écrit (`ApiToken::markBootstrapped()`, KL-11).
 *   L'inverse laisserait un appareil dit « à jour » alors que la réponse a
 *   échoué. Distinct de `lastUsedAt`, que l'authenticator bouge à chaque appel :
 *   « ce téléphone répond » n'est pas « ce téléphone est à jour ».
 * - **Une seule lecture, jamais d'écriture métier.** L'endpoint est en GET et le
 *   reste : le sens montant, c'est `PUT /api/schedule/{uuid}` (KL-16).
 */
final class BootstrapController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/bootstrap', name: 'api_bootstrap', methods: ['GET'])]
    public function __invoke(Request $request, BootstrapPayload $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $raw = $request->query->get('since');
        $since = null;

        if (null !== $raw && '' !== $raw) {
            $since = self::parseSince((string) $raw);

            if (null === $since) {
                return ApiProblem::response(
                    Response::HTTP_BAD_REQUEST,
                    'Le paramètre since doit être une date ISO 8601 (p. ex. 2026-07-31T14:05:00+02:00).',
                );
            }
        }

        $now = new \DateTimeImmutable();
        $body = $payload->build($user, $since, $now);

        $apiToken = $request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTRIBUTE);
        if ($apiToken instanceof ApiToken) {
            $apiToken->markBootstrapped($now);
            $this->em->flush();
        }

        // `JSON_UNESCAPED_UNICODE` : sans lui chaque caractère accentué part en
        // `\uXXXX`, six octets pour un. Sur des noms d'exercices et des consignes
        // écrits en français, c'est le poste d'économie le plus bête à ne pas
        // prendre — et le budget de la réponse est de 1 Mo.
        return (new JsonResponse($body))->setEncodingOptions(
            JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Une date ISO 8601, ou null.
     *
     * Le garde-fou de forme (`AAAA-MM-JJT…`) n'est pas décoratif : le
     * constructeur de `DateTimeImmutable` accepte aussi l'anglais relatif
     * (« yesterday », « now », « +3 days ») et même la chaîne vide. Un client qui
     * enverrait n'importe quoi obtiendrait alors un delta silencieusement faux —
     * une réponse plausible, avec les mauvaises données — là où un 400 se voit.
     */
    private static function parseSince(string $raw): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T/', $raw)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
