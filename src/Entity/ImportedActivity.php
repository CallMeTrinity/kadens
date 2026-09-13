<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Repository\ImportedActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une activité cardio réellement faite, **importée** d'une source externe (la
 * montre synchronise vers Intervals.icu, Kadens la relit). C'est le réalisé du
 * cardio : il ne se saisit jamais dans Kadens, il s'importe.
 *
 * **Deux vies, et la seconde est optionnelle.** Une activité existe d'abord pour
 * elle-même, rattachée à personne. Elle devient le réalisé d'une séance datée
 * quand on la rattache (`scheduledWorkout`), automatiquement si un seul candidat
 * est possible (`ActivityMatcher`), à la main sinon. La FK est en `SET NULL` :
 * retirer une séance du calendrier ne fait pas disparaître une sortie courue.
 *
 * Plusieurs activités par séance sont permises : une montre coupée puis relancée
 * produit deux enregistrements pour une seule sortie.
 *
 * Unités normalisées comme partout (§3) : mètres, secondes, bpm. L'allure est
 * **dérivée** (`getPaceSecondsPerKm`), jamais stockée : elle se déduit de deux
 * colonnes qui font foi, la stocker ouvrirait la porte à une troisième version.
 */
#[ORM\Entity(repositoryClass: ImportedActivityRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_imported_activity_source_external', columns: ['source', 'external_id'])]
class ImportedActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(enumType: ActivitySource::class)]
    private ActivitySource $source;

    /** Identifiant chez la source (« i12345678 » pour Intervals). Unique par source. */
    #[ORM\Column(length: 64)]
    private string $externalId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledWorkout $scheduledWorkout = null;

    /** Type brut de la source (« TrailRun », « GravelRide »), gardé pour l'affichage et un mapping futur. */
    #[ORM\Column(length: 64)]
    private string $sportType;

    /** Null = type non reconnu : l'activité est importée mais jamais rattachée automatiquement. */
    #[ORM\Column(nullable: true, enumType: ActivityType::class)]
    private ?ActivityType $activity = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    /**
     * Le jour **local** du départ. C'est lui qui rapproche une activité d'une
     * séance datée : une sortie à 23h30 à Paris est à 21h30 UTC, et le jour UTC
     * d'une sortie à 0h30 serait la veille.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $localDate;

    #[ORM\Column(nullable: true)]
    private ?int $distanceMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $movingSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $elapsedSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $elevationGainMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $averageHeartRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxHeartRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $averageCadence = null;

    #[ORM\Column(nullable: true)]
    private ?int $averageWatts = null;

    /**
     * Découpage au kilomètre, calculé depuis les streams à l'import.
     *
     * @var list<array{meters: int, seconds: int, averageHeartRate: ?int, elevationGain: ?int}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $splits = null;

    /**
     * Secondes passées dans chaque zone Z1..Z5, **avec les zones du profil Kadens
     * au moment de l'import**. Figé : modifier ses zones ensuite ne réécrit pas
     * l'historique. Null sans cardio ou sans FC max renseignée.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $hrZoneSeconds = null;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        User $owner,
        ActivitySource $source,
        string $externalId,
        string $sportType,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $localDate,
    ) {
        $this->owner = $owner;
        $this->source = $source;
        $this->externalId = $externalId;
        $this->sportType = $sportType;
        $this->startedAt = $startedAt;
        $this->localDate = $localDate;
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getSource(): ActivitySource
    {
        return $this->source;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getScheduledWorkout(): ?ScheduledWorkout
    {
        return $this->scheduledWorkout;
    }

    public function setScheduledWorkout(?ScheduledWorkout $scheduledWorkout): static
    {
        $this->scheduledWorkout = $scheduledWorkout;

        return $this;
    }

    public function getSportType(): string
    {
        return $this->sportType;
    }

    public function getActivity(): ?ActivityType
    {
        return $this->activity;
    }

    public function setActivity(?ActivityType $activity): static
    {
        $this->activity = $activity;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getDistanceMeters(): ?int
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?int $distanceMeters): static
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getMovingSeconds(): ?int
    {
        return $this->movingSeconds;
    }

    public function setMovingSeconds(?int $movingSeconds): static
    {
        $this->movingSeconds = $movingSeconds;

        return $this;
    }

    public function getElapsedSeconds(): ?int
    {
        return $this->elapsedSeconds;
    }

    public function setElapsedSeconds(?int $elapsedSeconds): static
    {
        $this->elapsedSeconds = $elapsedSeconds;

        return $this;
    }

    public function getElevationGainMeters(): ?int
    {
        return $this->elevationGainMeters;
    }

    public function setElevationGainMeters(?int $elevationGainMeters): static
    {
        $this->elevationGainMeters = $elevationGainMeters;

        return $this;
    }

    public function getAverageHeartRate(): ?int
    {
        return $this->averageHeartRate;
    }

    public function setAverageHeartRate(?int $averageHeartRate): static
    {
        $this->averageHeartRate = $averageHeartRate;

        return $this;
    }

    public function getMaxHeartRate(): ?int
    {
        return $this->maxHeartRate;
    }

    public function setMaxHeartRate(?int $maxHeartRate): static
    {
        $this->maxHeartRate = $maxHeartRate;

        return $this;
    }

    public function getAverageCadence(): ?int
    {
        return $this->averageCadence;
    }

    public function setAverageCadence(?int $averageCadence): static
    {
        $this->averageCadence = $averageCadence;

        return $this;
    }

    public function getAverageWatts(): ?int
    {
        return $this->averageWatts;
    }

    public function setAverageWatts(?int $averageWatts): static
    {
        $this->averageWatts = $averageWatts;

        return $this;
    }

    /**
     * @return list<array{meters: int, seconds: int, averageHeartRate: ?int, elevationGain: ?int}>|null
     */
    public function getSplits(): ?array
    {
        return $this->splits;
    }

    /**
     * @param list<array{meters: int, seconds: int, averageHeartRate: ?int, elevationGain: ?int}>|null $splits
     */
    public function setSplits(?array $splits): static
    {
        $this->splits = $splits;

        return $this;
    }

    /**
     * @return list<int>|null
     */
    public function getHrZoneSeconds(): ?array
    {
        return $this->hrZoneSeconds;
    }

    /**
     * @param list<int>|null $hrZoneSeconds
     */
    public function setHrZoneSeconds(?array $hrZoneSeconds): static
    {
        $this->hrZoneSeconds = $hrZoneSeconds;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    /**
     * Allure en secondes par kilomètre, sur le temps **en mouvement** (les arrêts
     * aux feux ne ralentissent pas une sortie). Null sans distance ou sans durée.
     */
    public function getPaceSecondsPerKm(): ?int
    {
        if (null === $this->distanceMeters || $this->distanceMeters <= 0 || null === $this->movingSeconds || $this->movingSeconds <= 0) {
            return null;
        }

        return (int) round($this->movingSeconds * 1000 / $this->distanceMeters);
    }
}
