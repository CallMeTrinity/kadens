<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les erreurs de l'API vues du client (KL-13) : quoi qu'il arrive sous `^/api`,
 * la réponse est un problème RFC 9457, jamais du HTML — un client mobile n'a
 * qu'un décodeur, et il ne parle pas Twig.
 *
 * Le test qui porte le périmètre est `testWebPagesKeepTheirHtmlErrorPages` : le
 * listener ne doit pas déborder, sinon les pages d'erreur du site sortiraient en
 * JSON. La forme en prod (aucun détail interne, aucune trace) est gardée par
 * `ApiExceptionListenerTest`, hors requête — `kernel.debug` étant vrai en test,
 * une requête HTTP ne prouverait rien de ce côté.
 */
final class ApiErrorResponseTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * Une URL d'API qui n'existe pas. Le routage précède le contrôle d'accès
     * (KL-10), donc cette 404 sort sans jamais réveiller le pare-feu : c'est bien
     * le listener qui la met en forme.
     */
    public function testAnUnknownApiRouteAnswersAProblem(): void
    {
        $this->client->request('GET', '/api/cette-route-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'Ressource introuvable.',
            ],
            $this->json(),
        );
    }

    /**
     * Le message du routeur récite l'URL demandée. Anodin ici, mais c'est la
     * règle qui compte : le `detail` est écrit pour le client, jamais repris
     * d'une exception.
     */
    public function testTheRouterMessageDoesNotLeakIntoTheResponse(): void
    {
        $this->client->request('GET', '/api/cette-route-nexiste-pas');

        self::assertStringNotContainsString('No route found', $this->client->getResponse()->getContent());
    }

    /**
     * Une méthode qui n'existe pas sur une route qui existe. L'en-tête `Allow`
     * de l'exception survit à la mise en forme : sans lui le 405 ne dit pas ce
     * qu'il aurait fallu appeler.
     */
    public function testAWrongMethodAnswersAProblemAndKeepsAllow(): void
    {
        $this->client->request('GET', '/api/auth/login');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('POST', $this->client->getResponse()->headers->get('Allow'));
        self::assertSame('Méthode non autorisée sur cette ressource.', $this->json()['detail']);
    }

    /**
     * Le 401 du pare-feu garde la même enveloppe que tout le reste : il est
     * produit par l'authenticator, pas par le listener (le pare-feu écoute avant
     * lui), et c'est `ApiProblem` qui garantit qu'ils ne divergent pas.
     */
    public function testTheFirewallAnswersTheSameShape(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(['type', 'title', 'status', 'detail'], array_keys($this->json()));
        self::assertSame('Unauthorized', $this->json()['title']);
    }

    /**
     * Le test de périmètre : hors `^/api`, rien ne change. Les pages d'erreur
     * Twig (404, 403, 5xx) doivent continuer de sortir en HTML — en test elles
     * cèdent la place à la page d'exception de développement, ce qui suffit :
     * l'une comme l'autre est du HTML, et c'est le format qu'on vérifie.
     */
    public function testWebPagesKeepTheirHtmlErrorPages(): void
    {
        $this->client->request('GET', '/cette-page-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('text/html', $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }
}
