<?php

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Le listener d'erreurs de l'API (KL-13), testé **hors requête HTTP** : c'est la
 * seule façon d'exercer le mode prod, `kernel.debug` étant vrai en test comme en
 * dev. Or c'est précisément en prod que porte la garde du ticket — aucune trace
 * de pile, et plus généralement aucun détail interne dans la réponse.
 *
 * Le comportement en situation (404, 405, périmètre) est couvert par
 * `ApiErrorResponseTest`, qui passe par le vrai noyau.
 */
final class ApiExceptionListenerTest extends TestCase
{
    /**
     * Le test qui porte le ticket : une panne rend un problème générique, et
     * rien de ce que l'exception raconte ne sort. Le message d'une exception est
     * écrit pour les journaux — ici, une chaîne de connexion.
     */
    public function testAnInternalErrorLeaksNothingInProduction(): void
    {
        $event = $this->dispatch(
            new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user \'kadens\'@\'localhost\''),
            debug: false,
        );

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString(__FILE__, $body);

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'detail' => 'Une erreur interne est survenue.',
            ],
            json_decode($body, true),
        );
    }

    /**
     * Hors prod, une 500 d'API sans indice serait un mur : le message est ajouté
     * en membre d'extension. La **trace**, elle, ne l'est jamais — le profileur
     * la garde, et ne pas l'écrire ici, c'est ne pas avoir de code qui n'aurait
     * pas le droit de tourner en prod.
     */
    public function testDebugAddsTheMessageButNeverATrace(): void
    {
        $event = $this->dispatch(new \RuntimeException('quelque chose a cassé'), debug: true);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('Une erreur interne est survenue.', $payload['detail']);
        self::assertStringContainsString('quelque chose a cassé', $payload['exception']);
        self::assertStringContainsString(\RuntimeException::class, $payload['exception']);
        self::assertArrayNotHasKey('trace', $payload);
    }

    /** Une erreur du client, même en debug, n'a rien à expliquer de plus. */
    public function testAClientErrorCarriesNoDebugExtension(): void
    {
        $event = $this->dispatch(new HttpException(404, 'App\Entity\Workout object not found by the @MapEntity annotation'), debug: true);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame(404, $event->getResponse()->getStatusCode());
        self::assertSame('Ressource introuvable.', $payload['detail']);
        self::assertArrayNotHasKey('exception', $payload);
        // Le message du framework nomme une classe interne : il ne sort pas.
        self::assertStringNotContainsString('Workout', $event->getResponse()->getContent());
    }

    /**
     * Les en-têtes d'une exception HTTP font partie de sa réponse : `Allow` dit
     * au client ce qu'il aurait dû appeler, le perdre rendrait le 405 muet.
     */
    public function testTheExceptionHeadersSurvive(): void
    {
        $event = $this->dispatch(new MethodNotAllowedHttpException(['POST']), debug: false);

        self::assertSame(405, $event->getResponse()->getStatusCode());
        self::assertSame('POST', $event->getResponse()->headers->get('Allow'));
    }

    /** Une validation nue est un 422, pas une panne : le client a mal rempli. */
    public function testValidationFailureListsTheOffendingFields(): void
    {
        $event = $this->dispatch($this->validationFailure(), debug: false);

        self::assertSame(422, $event->getResponse()->getStatusCode());

        $payload = json_decode($event->getResponse()->getContent(), true);
        self::assertSame('Les données envoyées sont invalides.', $payload['detail']);
        self::assertSame(
            [
                ['field' => 'weightKg', 'message' => 'Cette valeur doit être positive.'],
                ['field' => 'sets[0].reps', 'message' => 'Cette valeur ne doit pas être vide.'],
            ],
            $payload['violations'],
        );
    }

    /**
     * `#[MapRequestPayload]` n'expose pas la `ValidationFailedException` : elle
     * la met en `previous` d'une exception HTTP. S'arrêter au premier niveau
     * rendrait un 422 sans le moindre champ, ce qui est exactement l'inverse de
     * ce que le ticket demande.
     */
    public function testValidationFailureIsFoundThroughTheCauseChain(): void
    {
        $event = $this->dispatch(
            new HttpException(422, 'Validation Failed', $this->validationFailure()),
            debug: false,
        );

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame(422, $event->getResponse()->getStatusCode());
        self::assertCount(2, $payload['violations']);
    }

    /**
     * Le périmètre est le préfixe du pare-feu, et rien d'autre : hors `^/api`, le
     * listener ne touche à rien — c'est ce qui laisse vivre les pages d'erreur
     * Twig. `ApiErrorResponseTest` le vérifie de l'autre côté, par une vraie
     * requête.
     */
    public function testItIgnoresEverythingOutsideTheApi(): void
    {
        $event = $this->dispatch(new HttpException(404), debug: false, path: '/workout/42');

        self::assertNull($event->getResponse());
    }

    private function validationFailure(): ValidationFailedException
    {
        return new ValidationFailedException(new \stdClass(), new ConstraintViolationList([
            new ConstraintViolation('Cette valeur doit être positive.', null, [], null, 'weightKg', -10),
            new ConstraintViolation('Cette valeur ne doit pas être vide.', null, [], null, 'sets[0].reps', null),
        ]));
    }

    private function dispatch(\Throwable $throwable, bool $debug, string $path = '/api/schedule/42'): ExceptionEvent
    {
        $event = new ExceptionEvent(
            // Un stub, pas un mock : l'événement exige un noyau, le listener ne
            // l'appelle jamais.
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        (new ApiExceptionListener($debug))($event);

        return $event;
    }
}
