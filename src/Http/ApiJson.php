<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * La forme d'une réponse d'API **réussie**, pendant exact d'`ApiProblem` pour les
 * erreurs. Une seule raison d'exister, mais elle suffit : `JSON_UNESCAPED_UNICODE`.
 *
 * Sans ce drapeau, chaque caractère accentué part en `\uXXXX` — six octets pour
 * un. Sur des noms d'exercices, des consignes et des notes écrits en français,
 * c'est le poste d'économie le plus bête à ne pas prendre, et le budget d'un
 * bootstrap est de 1 Mo (KL-14). Un endpoint qui oublierait le drapeau ne
 * casserait rien de visible : il gonflerait la réponse en silence. C'est
 * exactement le genre d'oubli qu'on prévient en n'ayant qu'un seul endroit qui
 * fabrique la réponse.
 */
final class ApiJson
{
    /**
     * @param array<string, mixed> $body
     */
    public static function response(array $body, int $status = Response::HTTP_OK): JsonResponse
    {
        return (new JsonResponse($body, $status))->setEncodingOptions(
            JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE,
        );
    }
}
