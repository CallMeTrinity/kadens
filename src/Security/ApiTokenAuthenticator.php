<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * L'authentification de l'API mobile : `Authorization: Bearer <secret>`, et rien
 * d'autre. Le pare-feu `api` est **stateless** — aucune session, aucun cookie,
 * donc aucun CSRF à gérer — et il est déclaré **avant** `main` dans
 * `security.yaml` : le premier motif qui correspond gagne, et si `^/api` tombait
 * dans `main`, son `remember_me` à dix ans authentifierait la requête par cookie.
 * Le jeton deviendrait décoratif et la révocation d'un appareil, sans effet.
 *
 * Quatre responsabilités, pas une de plus : lire l'en-tête, valider le jeton,
 * repousser son échéance, et le publier sur la requête (cf. REQUEST_ATTRIBUTE).
 * L'émission vit ailleurs (KL-11, KL-46), la mise en forme normalisée des
 * erreurs arrivera en KL-13.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const string SCHEME = 'Bearer ';

    /**
     * Attribut de requête où atterrit le jeton validé. C'est ce qui permet à
     * `POST /api/auth/logout` de révoquer **celui qu'on présente** et à
     * `GET /api/me` de décrire l'appareil courant, sans relire l'en-tête ailleurs :
     * la lecture et la validation du Bearer restent l'affaire de cette classe.
     * Le préfixe `_` le tient hors des arguments de contrôleur résolus par nom.
     */
    public const string REQUEST_ATTRIBUTE = '_api_token';

    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Sans en-tête `Bearer`, aucun authenticator ne se déclenche : la requête
     * poursuit en anonyme et `access_control` la refuse, ce qui appelle
     * `start()`. C'est ce qui laisse `^/api/auth` public sans exception ici.
     */
    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), self::SCHEME);
    }

    public function authenticate(Request $request): Passport
    {
        $secret = trim(substr($request->headers->get('Authorization', ''), \strlen(self::SCHEME)));

        // Un seul message pour les trois cas (vide, inconnu, périmé) : le client
        // n'a rien à en tirer, et une réponse qui distingue « inconnu » de
        // « périmé » confirme l'existence d'un jeton à qui le devine.
        if ('' === $secret) {
            throw new CustomUserMessageAuthenticationException('Jeton absent ou invalide.');
        }

        $apiToken = $this->apiTokens->findOneByPlainToken($secret);

        if (null === $apiToken || $apiToken->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Jeton absent ou invalide.');
        }

        // Expiration glissante : l'usage est le fait qui prolonge, il se note ici
        // et nulle part ailleurs. Le flush est ciblé sur ce seul objet, rien
        // d'autre n'est encore en attente à ce stade de la requête.
        $apiToken->touch();
        $this->em->flush();

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $apiToken);

        $owner = $apiToken->getOwner();

        // SelfValidatingPassport : le porteur du secret EST authentifié, il n'y a
        // pas de second facteur à vérifier. Le chargeur rend l'utilisateur déjà
        // en mémoire plutôt que de le relire par son identifiant.
        return new SelfValidatingPassport(new UserBadge($owner->getUserIdentifier(), static fn (): User => $owner));
    }

    public function onAuthenticationSuccess(Request $request, mixed $token, string $firewallName): ?Response
    {
        // Rien à faire : la requête continue vers le contrôleur.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->unauthorized('Jeton absent ou invalide.');
    }

    /**
     * Point d'entrée du pare-feu : une ressource protégée demandée sans jeton
     * répond 401 en JSON, jamais une redirection vers le formulaire de connexion
     * web — le client est une app, pas un navigateur.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized('Authentification requise.');
    }

    private function unauthorized(string $detail): JsonResponse
    {
        // Forme RFC 9457, que KL-13 généralisera à toutes les erreurs de l'API.
        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => 'Unauthorized',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => $detail,
            ],
            Response::HTTP_UNAUTHORIZED,
            [
                'Content-Type' => 'application/problem+json',
                'WWW-Authenticate' => 'Bearer',
            ],
        );
    }
}
