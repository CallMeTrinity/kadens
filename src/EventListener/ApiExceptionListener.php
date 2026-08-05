<?php

namespace App\EventListener;

use App\Http\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Le filet de sécurité des erreurs de l'API : **toute** exception qui remonte
 * sous `^/api` sort en `application/problem+json` (RFC 9457), jamais en HTML.
 *
 * Il ne remplace pas les erreurs que les contrôleurs rendent eux-mêmes
 * (`AuthController`) ni le 401 de l'authenticator : ceux-là formulent un message
 * précis parce qu'ils savent ce qu'ils refusent. La répartition est nette —
 * **le contrôleur rend ses erreurs, le listener rattrape ce que personne n'a
 * rendu.** Tout passe par la même enveloppe (`ApiProblem`).
 *
 * Ce qu'il faut savoir avant d'y toucher :
 *
 * - **Le message d'une exception ne sort jamais dans la réponse.** Il est écrit
 *   pour les journaux : une `DriverException` porte le SQL, une exception de
 *   résolution d'argument porte un nom de classe interne, et même le
 *   `NotFoundHttpException` du routeur récite l'URL demandée. Le `detail` est
 *   donc choisi ici, par statut, en français. C'est aussi ce qui répond au
 *   « aucune trace de pile en prod » du ticket : il n'y a pas de chemin par
 *   lequel un détail interne puisse partir, pas seulement pas de trace.
 * - **Le périmètre est le préfixe littéral de `security.yaml`.** Le pare-feu
 *   `api` matche `^/api`, ce listener teste `str_starts_with('/api')` : le même
 *   motif au caractère près. Le raffiner ici (`^/api(/|$)`) créerait une zone où
 *   le pare-feu s'applique mais pas la mise en forme — un chemin qui sortirait
 *   en HTML sous un pare-feu stateless. Hors de ce préfixe, on ne fait rien du
 *   tout : les pages d'erreur Twig (404, 403, 5xx) continuent de se rendre.
 * - **Priorité -1, et ce n'est pas cosmétique.** Le pare-feu de sécurité écoute
 *   à 1 : il doit passer d'abord, c'est lui qui transforme un accès refusé en
 *   401 (via `ApiTokenAuthenticator::start()`) ou en 403. `ErrorListener` de
 *   Symfony, lui, écoute **deux fois** — la journalisation à 0, le rendu HTML à
 *   -128. Se placer entre les deux, c'est garder le journal (poser une réponse
 *   arrête la propagation, à 0 on jouerait à pile ou face avec le log) et
 *   supplanter le rendu.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
final class ApiExceptionListener
{
    /** Le préfixe du pare-feu `api` de `security.yaml`, à l'identique. */
    private const string API_PREFIX = '/api';

    /**
     * Le `detail` rendu pour chaque statut. Un statut absent retombe sur
     * l'erreur générique : mieux vaut un message vague qu'un message emprunté.
     */
    private const array DETAILS = [
        Response::HTTP_BAD_REQUEST => 'Requête invalide.',
        Response::HTTP_UNAUTHORIZED => 'Authentification requise.',
        Response::HTTP_FORBIDDEN => 'Accès refusé.',
        Response::HTTP_NOT_FOUND => 'Ressource introuvable.',
        Response::HTTP_METHOD_NOT_ALLOWED => 'Méthode non autorisée sur cette ressource.',
        Response::HTTP_NOT_ACCEPTABLE => 'Format de réponse non disponible.',
        Response::HTTP_CONFLICT => 'Conflit avec l\'état actuel de la ressource.',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'Corps de requête JSON attendu.',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'Les données envoyées sont invalides.',
        Response::HTTP_TOO_MANY_REQUESTS => 'Trop de requêtes. Réessayez dans un instant.',
        Response::HTTP_SERVICE_UNAVAILABLE => 'Service temporairement indisponible.',
    ];

    private const string FALLBACK_DETAIL = 'Une erreur interne est survenue.';

    public function __construct(
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), self::API_PREFIX)) {
            return;
        }

        $throwable = $event->getThrowable();
        $http = $throwable instanceof HttpExceptionInterface ? $throwable : null;
        $violations = self::violations($throwable);

        // Une validation qui échoue est un 422, même si personne ne l'a
        // enveloppée dans une exception HTTP : la `ValidationFailedException` du
        // validateur remonte nue quand on l'appelle à la main, et la rendre en
        // 500 dirait « panne » là où le client a simplement mal rempli. C'est la
        // *présence* de l'exception qui décide, pas le nombre de violations —
        // une liste vide reste une validation, pas une panne.
        $status = $http?->getStatusCode() ?? (null !== $violations ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_INTERNAL_SERVER_ERROR);

        $extensions = null !== $violations ? ['violations' => $violations] : [];

        if ($this->debug && $status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            // Uniquement hors prod, et uniquement sur une panne : sans ça, une
            // 500 d'API en développement n'est qu'un mur de texte identique à
            // toutes les autres. Le message, pas la trace — le profileur garde
            // la trace, et l'y chercher évite d'écrire ici du code qui n'aurait
            // jamais le droit de tourner en prod.
            $extensions['exception'] = \sprintf(
                '%s: %s (%s:%d)',
                $throwable::class,
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine(),
            );
        }

        $event->setResponse(ApiProblem::response(
            $status,
            self::DETAILS[$status] ?? self::FALLBACK_DETAIL,
            $extensions,
            // Les en-têtes que porte l'exception sont une partie de sa réponse,
            // pas une décoration : `Allow` sur un 405, `Retry-After` sur un 429,
            // `WWW-Authenticate` sur un 401.
            $http?->getHeaders() ?? [],
        ));
    }

    /**
     * Les champs fautifs d'une validation, ou **null** si l'exception n'en est
     * pas une — un tableau vide dirait « validation sans violation », ce qui
     * n'est pas la même chose et ne mérite pas le même statut.
     *
     * La cause est cherchée dans **toute** la chaîne : `#[MapRequestPayload]`
     * lève une exception HTTP 422 dont la `ValidationFailedException` n'est que
     * le `previous`, et s'en tenir au premier niveau laisserait passer les
     * violations sans les lister.
     *
     * @return list<array{field: string, message: string}>|null
     */
    private static function violations(\Throwable $throwable): ?array
    {
        for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
            if (!$cause instanceof ValidationFailedException) {
                continue;
            }

            $violations = [];

            /** @var ConstraintViolationInterface $violation */
            foreach ($cause->getViolations() as $violation) {
                $violations[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => (string) $violation->getMessage(),
                ];
            }

            return $violations;
        }

        return null;
    }
}
