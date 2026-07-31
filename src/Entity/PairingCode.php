<?php

namespace App\Entity;

use App\Repository\PairingCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code d'appairage à usage unique : le pont entre une session desktop et un
 * téléphone (§0.6 du cadrage). Le desktop en émet un, l'affiche en QR, l'app le
 * scanne et l'échange contre un `ApiToken`.
 *
 * **Le QR ne contient jamais de jeton**, seulement ce code : une photo de l'écran
 * ne vaut donc qu'un accès de deux minutes, à usage unique, et seulement si
 * personne ne l'a déjà consommé. C'est toute la raison d'être de cette table —
 * sans elle, il faudrait afficher le secret d'`ApiToken` à l'écran.
 *
 * Comme `ApiToken`, la base ne stocke que l'empreinte SHA-256 : le code clair
 * n'existe que dans la réponse qui l'émet et sur l'écran qui l'affiche. Nuance
 * assumée par rapport au jeton : 8 caractères sur un alphabet de 32, c'est 40
 * bits, pas 256 — une empreinte volée est cassable hors ligne. Ce qui la rend
 * sans intérêt, c'est la fenêtre de deux minutes et l'usage unique ; ce qui
 * protège l'entrée en ligne, c'est le limiteur de débit de `POST /api/auth/pair`.
 */
#[ORM\Entity(repositoryClass: PairingCodeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_pairing_code_hash', columns: ['code_hash'])]
class PairingCode
{
    /**
     * Deux minutes : le temps de sortir son téléphone et de viser l'écran, pas
     * celui d'aller chercher un café. Un code qui traîne à l'écran d'un poste
     * partagé ne doit pas rester échangeable.
     */
    public const string LIFETIME = 'PT2M';

    /** Assez court pour se retaper à la main en repli, assez long pour ne pas se deviner. */
    public const int LENGTH = 8;

    /**
     * Alphabet **sans ambiguïté de lecture** : ni `O`/`0`, ni `I`/`1`/`l`. Le
     * code doit rester saisissable au clavier quand la caméra refuse (§0.6
     * règle 4), et une confusion de caractère y coûterait un aller-retour.
     * Il reste 32 symboles, donc 40 bits d'entropie sur huit caractères.
     */
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * L'émetteur du code, et donc le futur propriétaire du jeton : un code est
     * lié à la session desktop qui l'a produit (§0.6 règle 3). C'est ce qui
     * interdit de s'appairer au compte d'un autre en devinant un code.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Empreinte SHA-256 du code, en hexadécimal. Jamais le code lui-même. */
    #[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /**
     * Date de consommation. Null = jamais échangé. C'est la colonne sur laquelle
     * porte l'`UPDATE ... WHERE used_at IS NULL` de
     * `PairingCodeRepository::consume()` : l'usage unique est une garantie de la
     * base, pas une intention du code PHP.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * Nom de l'appareil qui a consommé le code, en snapshot. Ce n'est pas une
     * relation vers l'`ApiToken` créé : celui-ci se révoque (KL-12) et la trace
     * de l'appairage disparaîtrait avec lui, alors qu'elle sert justement à
     * confirmer sur le desktop *quel* téléphone vient de se connecter (KL-47).
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $consumedByDevice = null;

    /**
     * Le code en clair entre ici et n'en ressort pas, comme pour `ApiToken` : il
     * est haché sur place, aucun chemin ne permet de le persister par distraction.
     */
    public function __construct(User $owner, string $plainCode, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();

        $this->owner = $owner;
        $this->codeHash = self::hash($plainCode);
        $this->createdAt = $now;
        $this->expiresAt = $now->add(new \DateInterval(self::LIFETIME));
    }

    /**
     * Fabrique le code à afficher. Point unique : personne d'autre ne décide de
     * sa longueur ni de son alphabet.
     */
    public static function generateCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = \strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Normalise avant de hacher : le repli clavier se tape en minuscules aussi,
     * et un espace collé par un copier-coller n'est pas une faute de saisie.
     * Sans ça, le code affiché et le code tapé ne donneraient pas la même
     * empreinte — et le message d'erreur uniforme rendrait la panne indéchiffrable.
     */
    public static function hash(string $plainCode): string
    {
        return hash('sha256', strtoupper(trim($plainCode)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getConsumedByDevice(): ?string
    {
        return $this->consumedByDevice;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }
}
