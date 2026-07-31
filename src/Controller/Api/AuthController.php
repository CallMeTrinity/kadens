<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\PairingCode;
use App\Entity\User;
use App\Repository\PairingCodeRepository;
use App\Repository\UserRepository;
use App\Security\ApiTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'authentification de l'app mobile : obtenir un jeton, le rendre, dire qui on
 * est. Quatre routes, et rien d'autre — **pas de parcours d'inscription** (les
 * comptes se créent en console, règle verrouillée de CLAUDE.md §3) et pas de mot
 * de passe oublié.
 *
 * Le chemin nominal d'un appairage est le QR (`pair`, KL-46) : le mot de passe
 * (`login`, KL-11) reste le repli quand la caméra refuse, et il est de toute
 * façon nécessaire aux tests fonctionnels de l'API. Les deux chemins convergent
 * sur le même geste — émettre un `ApiToken` et rendre son secret **une seule
 * fois** (`issue()` ci-dessous). Ils diffèrent sur un point qui compte : `login`
 * lit le compte dans la requête, `pair` le lit dans le code consommé. Le
 * téléphone ne choisit jamais le compte auquel il se rattache.
 *
 * Contrat client à respecter : sur `/api/auth/login`, **ne pas envoyer**
 * d'en-tête `Authorization`. L'authenticator se déclenche sur la seule présence
 * d'un `Bearer`, quel que soit l'`access_control` de la route (KL-10 a
 * volontairement refusé d'y écrire une liste d'exceptions) : un jeton périmé
 * présenté ici ferait échouer la requête **avant** le contrôleur. Le flux d'une
 * reconnexion est donc : 401 → effacer le jeton local → login sans en-tête.
 * `logout`, lui, l'exige — c'est le jeton qu'il révoque.
 */
final class AuthController extends AbstractController
{
    /** Borne du `VARCHAR(100)` d'`ApiToken.deviceName` : refusée ici plutôt que tronquée en base. */
    private const int DEVICE_NAME_MAX = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Connexion par mot de passe. Rend le secret en clair : c'est le seul endroit
     * avec l'appairage (KL-46), et il ne sera plus jamais lisible ensuite.
     */
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $payload = $this->decode($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $email = $this->text($payload, 'email');
        $password = $this->text($payload, 'password');
        $deviceName = $this->text($payload, 'deviceName');

        if (null === $email || null === $password || null === $deviceName) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Les champs email, password et deviceName sont requis.');
        }

        if (mb_strlen($deviceName) > self::DEVICE_NAME_MAX) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', \sprintf('Le nom d\'appareil ne peut pas dépasser %d caractères.', self::DEVICE_NAME_MAX));
        }

        $user = $users->findOneBy(['email' => $email]);

        if (null === $user) {
            // Hachage à vide sur un compte inexistant : sans lui, une réponse
            // instantanée trahirait que l'email est inconnu là où le message,
            // lui, ne le dit pas. Le message uniforme ne suffit pas, le temps
            // de réponse parle aussi.
            $hasher->hashPassword(new User(), $password);

            return $this->invalidCredentials();
        }

        if (!$hasher->isPasswordValid($user, $password)) {
            return $this->invalidCredentials();
        }

        return $this->issue($user, $deviceName);
    }

    /**
     * Appairage : le chemin nominal (§0.6). Le téléphone présente le code lu sur
     * le QR du desktop et repart avec un jeton — celui de l'utilisateur qui a
     * émis le code, jamais d'un autre.
     *
     * Trois choses portent le ticket ici :
     *
     * - **Consommation atomique**, déléguée à `PairingCodeRepository::consume()` :
     *   deux scans simultanés du même QR ne peuvent pas réussir tous les deux.
     * - **Une seule erreur** pour « inconnu », « expiré » et « déjà utilisé ».
     *   Les distinguer dirait à qui devine un code s'il a visé juste — même
     *   raisonnement que le 401 uniforme de la connexion.
     * - **Limiteur de débit par IP** : 8 caractères sur 32 symboles, c'est 40
     *   bits. Assez pour ne pas se deviner, pas assez pour encaisser une force
     *   brute non bridée. Le limiteur est ici une pièce du modèle de sécurité.
     *
     * Le 429 est rendu **avant** toute lecture de la base : un quota épuisé n'a
     * pas à consommer une requête SQL de plus.
     */
    #[Route('/api/auth/pair', name: 'api_auth_pair', methods: ['POST'])]
    public function pair(
        Request $request,
        PairingCodeRepository $pairingCodes,
        #[Target('apiPairingLimiter')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $quota = $limiter->create($request->getClientIp())->consume();

        if (!$quota->isAccepted()) {
            $response = $this->problem(Response::HTTP_TOO_MANY_REQUESTS, 'Too Many Requests', 'Trop de tentatives d\'appairage. Réessayez dans un instant.');
            $response->headers->set('Retry-After', (string) max(1, $quota->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $payload = $this->decode($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $code = $this->text($payload, 'code');
        $deviceName = $this->text($payload, 'deviceName');

        if (null === $code || null === $deviceName) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Les champs code et deviceName sont requis.');
        }

        if (mb_strlen($deviceName) > self::DEVICE_NAME_MAX) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', \sprintf('Le nom d\'appareil ne peut pas dépasser %d caractères.', self::DEVICE_NAME_MAX));
        }

        $pairingCode = $pairingCodes->consume($code, $deviceName);

        if (!$pairingCode instanceof PairingCode) {
            return $this->invalidPairingCode();
        }

        // L'owner vient du code, pas de la requête : c'est la garde du §0.6
        // règle 3. Le téléphone ne choisit pas le compte auquel il se rattache.
        return $this->issue($pairingCode->getOwner(), $deviceName);
    }

    /**
     * Révoque le jeton présenté, et lui seul : déconnecter un téléphone ne touche
     * pas aux autres appareils du compte (« tout révoquer » est un geste explicite
     * de `/profile/settings`, KL-12). Le jeton est **supprimé**, pas expiré — une
     * ligne périmée qu'on garde n'est qu'une ligne à purger plus tard.
     *
     * La route est sous `^/api/auth`, donc publique pour `access_control` : la
     * garde est ici, et elle porte sur le jeton, pas sur l'utilisateur — c'est le
     * jeton qui est l'objet de l'action.
     */
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $apiToken = $this->currentToken($request);

        if (!$apiToken instanceof ApiToken) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'Jeton absent ou invalide.');
        }

        $this->em->remove($apiToken);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Qui je suis, et où en est cet appareil. Sous `^/api` (hors `^/api/auth`),
     * donc protégé par `access_control` : y arriver, c'est être authentifié.
     *
     * C'est ici que vit l'identité — `GET /api/ping` reste muet dessus (KL-10),
     * une sonde n'a pas à divulguer un email.
     */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'user' => self::userPayload($user),
            'device' => self::devicePayload($this->currentToken($request)),
        ]);
    }

    /**
     * Le geste d'émission, partagé par la connexion et l'appairage (KL-46) : le
     * secret naît, il est haché par le constructeur d'`ApiToken`, et il ne sort
     * que par cette réponse.
     *
     * 201 et non 200 : l'appel enregistre un **appareil**, que `/profile/settings`
     * listera et pourra révoquer. Ce n'est pas une simple lecture.
     */
    private function issue(User $owner, string $deviceName): JsonResponse
    {
        $secret = ApiToken::generateSecret();

        $this->em->persist(new ApiToken($owner, $deviceName, $secret));
        $this->em->flush();

        return $this->json([
            'token' => $secret,
            'user' => self::userPayload($owner),
        ], Response::HTTP_CREATED);
    }

    /**
     * Le jeton validé par l'authenticator, s'il y en a un. Aucune relecture de
     * l'en-tête ici : `ApiTokenAuthenticator` reste la seule autorité sur ce que
     * vaut un `Bearer`.
     */
    private function currentToken(Request $request): ?ApiToken
    {
        $apiToken = $request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTRIBUTE);

        return $apiToken instanceof ApiToken ? $apiToken : null;
    }

    /**
     * @return array{id: int|null, email: string, roles: list<string>, coach: bool}
     */
    private static function userPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getUserIdentifier(),
            'roles' => array_values($user->getRoles()),
            // Dérivable des rôles, mais le mobile n'a pas à connaître la
            // convention de nommage de Symfony pour savoir quoi afficher.
            'coach' => $user->isCoach(),
        ];
    }

    /**
     * @return array{name: string, lastUsedAt: string|null, lastBootstrapAt: string|null, expiresAt: string}|null
     */
    private static function devicePayload(?ApiToken $apiToken): ?array
    {
        if (null === $apiToken) {
            return null;
        }

        return [
            'name' => $apiToken->getDeviceName(),
            'lastUsedAt' => $apiToken->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            // Null tant que l'appareil n'a jamais fait de `GET /api/bootstrap`
            // (KL-14) : c'est ce qui distingue « appairé » de « synchronisé ».
            'lastBootstrapAt' => $apiToken->getLastBootstrapAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt' => $apiToken->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Corps JSON décodé, ou la réponse d'erreur à rendre tel quel.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function decode(Request $request): array|JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Corps de requête JSON attendu.');
        }

        return $payload;
    }

    /**
     * Une chaîne non vide, ou null. Un `deviceName` à ' ' ou un `email` numérique
     * ne sont pas des valeurs, et une base n'a pas à trancher ça pour nous.
     *
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * Une seule réponse pour « email inconnu » et « mot de passe faux ». Les
     * distinguer transformerait la connexion en oracle d'existence de compte.
     */
    private function invalidCredentials(): JsonResponse
    {
        return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'Identifiants invalides.');
    }

    /**
     * Une seule réponse pour « code inconnu », « code expiré » et « code déjà
     * utilisé ». 400 et non 401 : le client n'a pas à réessayer avec le même
     * code, il doit en demander un autre au desktop.
     */
    private function invalidPairingCode(): JsonResponse
    {
        return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Code d\'appairage invalide ou expiré.');
    }

    /**
     * Forme RFC 9457, la même que celle de l'authenticator. KL-13 la remontera
     * dans un listener d'exception pour toute l'API ; en attendant, les seules
     * erreurs que ce contrôleur produit passent par ici.
     */
    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
