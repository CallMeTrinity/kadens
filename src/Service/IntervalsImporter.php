<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedActivity;
use App\Entity\User;
use App\Enum\ActivitySource;
use App\Repository\ImportedActivityRepository;
use App\Repository\IntervalsConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le geste « Synchroniser » : relit les activités récentes d'Intervals.icu,
 * importe celles que Kadens ne connaît pas et les rattache quand c'est sûr.
 *
 * **Rejouable.** L'unicité (`source`, `externalId`) est la garantie ; la liste
 * des identifiants connus est lue en une requête avant tout appel coûteux, donc
 * relancer ne réimporte rien et ne redemande aucun stream.
 *
 * **Fenêtre glissante avec recouvrement.** On relit depuis `syncedThrough − 7
 * jours`, pas depuis `syncedThrough` : une montre synchronisée en retard dépose
 * dans Intervals, aujourd'hui, une sortie datée d'avant-hier. Sans recouvrement,
 * elle tomberait derrière la fenêtre et ne serait jamais importée. Première
 * synchro : les 30 derniers jours.
 *
 * **Lot borné.** Chaque activité coûte un appel de streams. On en traite au plus
 * `BATCH` par clic, des plus anciennes aux plus récentes, et `syncedThrough`
 * n'avance que jusqu'à la dernière **traitée** : le clic suivant reprend là.
 */
final class IntervalsImporter
{
    public const int BATCH = 40;

    private const int FIRST_SYNC_DAYS = 30;

    private const int OVERLAP_DAYS = 7;

    public function __construct(
        private readonly IntervalsConnectionRepository $connections,
        private readonly ImportedActivityRepository $activities,
        private readonly IntervalsClient $client,
        private readonly SecretBox $secretBox,
        private readonly ImportedActivityMapper $mapper,
        private readonly ActivityStreamAnalyzer $analyzer,
        private readonly ActivityMatcher $matcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Une panne en cours de lot (clé révoquée entre deux appels, source qui
     * tombe) conserve ce qui a été importé avant elle : l'exception remonte
     * après l'écriture, pas à sa place.
     *
     * @throws IntervalsAuthException        clé refusée par Intervals
     * @throws IntervalsUnavailableException source injoignable ou en erreur
     */
    public function sync(User $user, ?\DateTimeImmutable $today = null): SyncReport
    {
        $connection = $this->connections->findForOwner($user)
            ?? throw new \LogicException('Aucun compte Intervals.icu connecté.');

        $today ??= new \DateTimeImmutable('today');
        $apiKey = $this->secretBox->open($connection->getSealedApiKey());

        $oldest = null === $connection->getSyncedThrough()
            ? $today->modify(\sprintf('-%d days', self::FIRST_SYNC_DAYS))
            : $connection->getSyncedThrough()->modify(\sprintf('-%d days', self::OVERLAP_DAYS));

        $payloads = $this->client->activities($apiKey, $oldest, $today);

        // L'API rend du plus récent au plus ancien ; on traite dans l'ordre du
        // temps pour que `syncedThrough` puisse avancer sans rien sauter.
        usort($payloads, static fn (array $a, array $b): int => strcmp((string) ($a['start_date_local'] ?? ''), (string) ($b['start_date_local'] ?? '')));

        $ids = array_values(array_filter(array_map(
            static fn (array $p): ?string => isset($p['id']) && \is_scalar($p['id']) ? (string) $p['id'] : null,
            $payloads,
        )));
        $known = array_flip($this->activities->knownExternalIds(ActivitySource::INTERVALS, $ids));

        $fresh = array_values(array_filter(
            $payloads,
            static fn (array $p): bool => isset($p['id']) && \is_scalar($p['id']) && !isset($known[(string) $p['id']]),
        ));

        $imported = 0;
        $attached = 0;
        $skipped = 0;
        $processed = 0;
        $lastDate = null;
        $this->matcher->reset();

        try {
            foreach ($fresh as $payload) {
                $activity = $this->mapper->fromIntervals($user, $payload);

                if (null === $activity) {
                    ++$skipped;
                    ++$processed;

                    continue;
                }

                if ($imported >= self::BATCH) {
                    break;
                }

                $this->enrich($user, $apiKey, $activity);

                $this->entityManager->persist($activity);
                ++$imported;
                ++$processed;
                $lastDate = $activity->getLocalDate();

                if (null !== $this->matcher->autoAttach($activity)) {
                    ++$attached;
                }
            }
        } finally {
            $remaining = \count($fresh) - $processed;

            // Tout traité : la fenêtre peut avancer jusqu'à aujourd'hui. Lot
            // coupé (quota ou panne) : jusqu'au jour de la dernière activité
            // réellement importée, et pas plus loin.
            if (0 === $remaining) {
                $connection->setSyncedThrough($today);
            } elseif (null !== $lastDate) {
                $connection->setSyncedThrough($lastDate);
            }

            $connection->setLastSyncedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return new SyncReport($imported, $attached, $skipped, $remaining);
    }

    /**
     * Splits et zones depuis les streams. Une activité sans temps enregistré
     * (saisie manuelle dans Intervals) n'en a pas : on ne les demande pas.
     */
    private function enrich(User $user, string $apiKey, ImportedActivity $activity): void
    {
        if (null === $activity->getMovingSeconds() && null === $activity->getElapsedSeconds()) {
            return;
        }

        $streams = $this->client->streams($apiKey, $activity->getExternalId(), ActivityStreamAnalyzer::STREAM_TYPES);
        $analysis = $this->analyzer->analyze($user, $streams);

        $activity->setSplits($analysis['splits']);
        $activity->setHrZoneSeconds($analysis['hrZoneSeconds']);
    }
}
