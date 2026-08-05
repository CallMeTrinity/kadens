<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeletedEntityType;
use App\Repository\DeletedEntityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une **pierre tombale** : la trace qu'une chose a existé et n'existe plus.
 *
 * Sans elle, un delta (`GET /api/bootstrap?since=…`) ne sait dire que ce qui a
 * changé, jamais ce qui a disparu, et la base locale du téléphone accumule des
 * fantômes — un exercice supprimé de la bibliothèque resterait proposé pendant
 * des mois.
 *
 * **Pourquoi une table et non un `deletedAt` sur les entités concernées.** Le
 * `deletedAt` (suppression douce) est le choix qui semble le plus léger et qui
 * coûte le plus cher : il ne supprime rien, il *cache*, et il faut alors le
 * filtrer dans **chaque** requête du site — index de bibliothèque, sélecteurs de
 * pose, calendrier, export, ICS, page publique. Un oubli n'est pas une erreur,
 * c'est une ligne morte qui réapparaît en silence. La pierre tombale, elle, ne
 * touche à rien : la ligne est bel et bien supprimée, et ce qu'on garde n'est
 * plus l'entité mais son avis de décès.
 *
 * Trois conséquences à ne pas casser :
 *
 * - **Elle porte une clé, pas une relation.** Une clé étrangère vers une ligne
 *   supprimée n'existe pas ; `entityKey` est donc l'identifiant tel que le
 *   client le connaît — l'`id` d'un `Exercise`, l'`uuid` d'un `ScheduledWorkout`
 *   (le mobile ne connaît que celui-là pour ce qu'il a créé lui-même).
 * - **`owner` dit à qui l'annoncer**, et il est nullable : un exercice de la
 *   bibliothèque globale n'a pas de propriétaire, sa disparition regarde tout le
 *   monde. Le `ON DELETE CASCADE` est ici sans danger — un compte supprimé n'a
 *   plus de téléphone à prévenir.
 * - **Elle se purge** (`app:deleted:purge`). Une pierre tombale n'intéresse
 *   qu'un client dont le dernier `since` est antérieur ; passé un délai plus
 *   long que toute panne de synchronisation plausible, elle n'est plus qu'une
 *   ligne. Un client trop en retard repart de zéro (bootstrap complet), ce qui
 *   est de toute façon la bonne réponse.
 */
#[ORM\Entity(repositoryClass: DeletedEntityRepository::class)]
#[ORM\Index(name: 'idx_deleted_entity_deleted_at', columns: ['deleted_at'])]
class DeletedEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: DeletedEntityType::class)]
    private DeletedEntityType $entityType;

    /** L'identifiant que le client connaît : `id` pour un exercice, `uuid` pour une séance datée. */
    #[ORM\Column(length: 36)]
    private string $entityKey;

    /** À qui la disparition doit être annoncée. null = tout le monde (bibliothèque globale). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $owner;

    #[ORM\Column]
    private \DateTimeImmutable $deletedAt;

    public function __construct(
        DeletedEntityType $entityType,
        string $entityKey,
        ?User $owner = null,
        ?\DateTimeImmutable $deletedAt = null,
    ) {
        $this->entityType = $entityType;
        $this->entityKey = $entityKey;
        $this->owner = $owner;
        $this->deletedAt = $deletedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityType(): DeletedEntityType
    {
        return $this->entityType;
    }

    public function getEntityKey(): string
    {
        return $this->entityKey;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
