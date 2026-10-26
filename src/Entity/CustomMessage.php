<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageAudience;
use App\Repository\CustomMessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un message programmé : un texte et un GIF facultatif, montrés **une fois** à
 * leurs destinataires entre deux dates (anniversaire, Joyeux Noël). Écrit dans
 * le back-office `/admin/messages`, lu dans une modale sur le web et sur le
 * téléphone. Invariants : `docs/feature-custom-messages.md`.
 *
 * - `title` est un libellé **interne** : il range le message dans le
 *   back-office et ne sort jamais vers un destinataire (ni modale, ni API).
 * - Les bornes sont des **jours entiers**, incluses toutes les deux, comme les
 *   séances datées : pas d'heure, pas de fuseau.
 * - `seenBy` est la seule mémoire du « vu », commune au web et au téléphone :
 *   fermer la modale d'un côté la retire de l'autre.
 */
#[ORM\Entity(repositoryClass: CustomMessageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CustomMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Donne un nom au message.')]
    #[Assert\Length(max: 120)]
    private ?string $title = null;

    // Texte brut : les sauts de ligne se rendent à l'affichage, rien d'autre.
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le message est vide.')]
    #[Assert\Length(max: 2000)]
    private ?string $body = null;

    // Nom du fichier rangé par MessageMediaStorage, jamais un chemin.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mediaFile = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Choisis une date de début.')]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Choisis une date de fin.')]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(length: 10, enumType: MessageAudience::class)]
    private MessageAudience $audience = MessageAudience::USERS;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'custom_message_recipient')]
    private Collection $recipients;

    /**
     * @var Collection<int, User>
     */
    // EXTRA_LAZY : le back-office n'en lit que le compte.
    #[ORM\ManyToMany(targetEntity: User::class, fetch: 'EXTRA_LAZY')]
    #[ORM\JoinTable(name: 'custom_message_seen')]
    private Collection $seenBy;

    // Éteindre un message sans le supprimer (et sans perdre qui l'a vu).
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->recipients = new ArrayCollection();
        $this->seenBy = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null !== $this->startsOn && null !== $this->endsOn && $this->endsOn < $this->startsOn) {
            $context->buildViolation('La fin ne peut pas précéder le début.')
                ->atPath('endsOn')
                ->addViolation();
        }

        if (MessageAudience::USERS === $this->audience && $this->recipients->isEmpty()) {
            $context->buildViolation('Choisis au moins un destinataire.')
                ->atPath('recipients')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getMediaFile(): ?string
    {
        return $this->mediaFile;
    }

    public function setMediaFile(?string $mediaFile): static
    {
        $this->mediaFile = $mediaFile;

        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): static
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(?\DateTimeImmutable $endsOn): static
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function getAudience(): MessageAudience
    {
        return $this->audience;
    }

    public function setAudience(MessageAudience $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    public function addRecipient(User $user): static
    {
        if (!$this->recipients->contains($user)) {
            $this->recipients->add($user);
        }

        return $this;
    }

    public function removeRecipient(User $user): static
    {
        $this->recipients->removeElement($user);

        return $this;
    }

    /**
     * Le message concerne-t-il ce compte, dates et « vu » mis à part ?
     * Comparaison par `id` : `User` peut être un proxy.
     */
    public function isAddressedTo(User $user): bool
    {
        if (MessageAudience::ALL === $this->audience) {
            return true;
        }

        foreach ($this->recipients as $recipient) {
            if ($recipient->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, User>
     */
    public function getSeenBy(): Collection
    {
        return $this->seenBy;
    }

    /** Idempotent : marquer deux fois vu ne change rien. */
    public function markSeenBy(User $user): static
    {
        if (!$this->seenBy->contains($user)) {
            $this->seenBy->add($user);
        }

        return $this;
    }

    public function resetSeen(): static
    {
        $this->seenBy->clear();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
