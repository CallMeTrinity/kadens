<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton d'accès à l'API mobile. Opaque et stocké **haché** : la base ne contient
 * jamais de quoi rejouer une requête, seule l'empreinte SHA-256 du secret y vit.
 * Le secret en clair n'existe qu'une fois, dans la réponse qui le crée
 * (connexion par mot de passe en KL-11, appairage par QR en KL-46) ; s'il se
 * perd, on en émet un autre, on ne le retrouve pas.
 *
 * SHA-256 nu et non un hachage de mot de passe (bcrypt, argon) : le secret fait
 * 256 bits d'aléa, il n'y a pas de dictionnaire à ralentir, et l'authentification
 * doit tenir sur **une lecture indexée** à chaque requête d'API.
 *
 * **Expiration glissante** : chaque usage repousse `expiresAt` de 90 jours
 * (`touch()`). Un téléphone utilisé ne se déconnecte donc jamais ; un téléphone
 * perdu, oui — et `/profile/settings` (KL-12) le révoque avant.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_api_token_hash', columns: ['token_hash'])]
class ApiToken
{
    /**
     * Fenêtre de vie, repartie de zéro à chaque usage (§0.6 : c'est ce qui rend
     * l'appairage un geste trimestriel plutôt qu'hebdomadaire).
     */
    public const string LIFETIME = 'P90D';

    /** 32 octets d'aléa → 64 caractères hexadécimaux, comme le jeton ICS. */
    private const int SECRET_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Empreinte SHA-256 du secret, en hexadécimal. Jamais le secret lui-même. */
    #[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
    private string $tokenHash;

    /** Nom d'appareil fourni par le client, affiché tel quel dans la liste (KL-12). */
    #[ORM\Column(length: 100)]
    private string $deviceName;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /**
     * Date de la dernière hydratation complète de cet appareil (`GET /api/bootstrap`,
     * KL-14, seul écrivain). Distincte de `lastUsedAt`, qui bouge à *chaque* appel :
     * un téléphone peut pinguer tous les jours sans jamais resynchroniser. C'est
     * donc celle-ci qui dit « cet appareil travaille sur des données de trois
     * semaines », et c'est elle que `GET /api/me` et la liste de KL-12 affichent.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastBootstrapAt = null;

    /**
     * Le secret en clair entre ici et n'en ressort pas : il est haché sur place.
     * Aucun appelant n'a donc de raison de le conserver au-delà de la réponse
     * qui le renvoie.
     */
    public function __construct(User $owner, string $deviceName, string $plainToken, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();

        $this->owner = $owner;
        $this->deviceName = $deviceName;
        $this->tokenHash = self::hash($plainToken);
        $this->createdAt = $now;
        $this->expiresAt = $now->add(new \DateInterval(self::LIFETIME));
    }

    /**
     * Fabrique le secret à confier au client. Point unique : personne d'autre ne
     * décide de l'entropie d'un jeton.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(self::SECRET_BYTES));
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getDeviceName(): string
    {
        return $this->deviceName;
    }

    public function setDeviceName(string $deviceName): static
    {
        $this->deviceName = $deviceName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastBootstrapAt(): ?\DateTimeImmutable
    {
        return $this->lastBootstrapAt;
    }

    /**
     * Note une hydratation complète. Appelé par `GET /api/bootstrap` (KL-14) et
     * par lui seul : un appel qui ne rend pas le jeu complet ne doit pas laisser
     * croire que l'appareil est à jour.
     */
    public function markBootstrapped(?\DateTimeImmutable $now = null): static
    {
        $this->lastBootstrapAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /**
     * Marque l'usage et repousse l'échéance. Appelé par l'authenticator à chaque
     * requête authentifiée, jamais ailleurs : `lastUsedAt` doit rester la date
     * d'un usage réel, c'est ce que KL-12 affiche pour décider d'une révocation.
     */
    public function touch(?\DateTimeImmutable $now = null): static
    {
        $now ??= new \DateTimeImmutable();

        $this->lastUsedAt = $now;
        $this->expiresAt = $now->add(new \DateInterval(self::LIFETIME));

        return $this;
    }
}
