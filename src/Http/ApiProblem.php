<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * La forme d'une erreur d'API, et **la seule** (RFC 9457) :
 * `{type, title, status, detail}` en `application/problem+json`.
 *
 * Trois endroits produisent des erreurs sur `^/api` — l'authenticator
 * (`ApiTokenAuthenticator`), les contrôleurs qui refusent une requête avant même
 * d'agir (`AuthController`), et le filet de sécurité qui rattrape tout le reste
 * (`ApiExceptionListener`). Ils passent tous par ici : trois définitions de la
 * même enveloppe auraient fini par diverger sur une clé, et le client mobile n'a
 * qu'un seul décodeur d'erreur.
 *
 * Deux partis pris à ne pas défaire :
 *
 * - **Le `title` se dérive du statut, il ne s'écrit jamais à la main.** C'est un
 *   libellé de catégorie, pas un message ; l'appelant qui le choisirait pourrait
 *   le mettre en désaccord avec le `status` de la même réponse. Il reste en
 *   anglais (le vocabulaire HTTP), là où le `detail` est en français parce qu'il
 *   est, lui, destiné à être lu.
 * - **Le `detail` est un message pour le client, jamais pour les journaux.** Un
 *   message d'exception interne (requête SQL, chemin de fichier, nom de classe)
 *   n'a rien à faire dans une réponse : c'est `ApiExceptionListener` qui tient
 *   cette règle, et il ne peut la tenir que parce que le detail se passe
 *   explicitement ici.
 */
final class ApiProblem
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param array<string, mixed> $extensions membres d'extension RFC 9457 (p. ex. `violations`), rendus après `detail`
     * @param array<string, string> $headers en-têtes de la réponse (p. ex. `Retry-After`, `Allow`, `WWW-Authenticate`)
     */
    public static function response(int $status, string $detail, array $extensions = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(
            [
                // `about:blank` est la valeur que la RFC prescrit quand le type
                // de problème n'ajoute rien au statut. Publier une URL de
                // documentation par type serait un contrat de plus à tenir, pour
                // un client unique qui lit déjà `status`.
                'type' => 'about:blank',
                'title' => self::title($status),
                'status' => $status,
                'detail' => $detail,
            ] + $extensions,
            $status,
            ['Content-Type' => self::CONTENT_TYPE] + $headers,
        );
    }

    /**
     * Le libellé HTTP du statut (« Unauthorized », « Too Many Requests »), ou
     * « Error » pour un code que Symfony ne nomme pas — une réponse doit sortir
     * même quand le statut est exotique.
     */
    public static function title(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }
}
