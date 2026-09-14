<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedActivity;
use App\Entity\IntervalsConnection;
use App\Entity\User;
use App\Enum\ActivityMatch;
use App\Enum\ActivitySource;
use App\Repository\ImportedActivityRepository;
use App\Repository\IntervalsConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Relit les activités d'Intervals.icu, importe celles que Kadens ne connaît pas
 * et les rattache quand c'est sûr. Deux entrées, un seul chemin d'import :
 *
 * - `sync()` : le bouton « Synchroniser » du web. Fenêtre récente, lot borné,
 *   jamais de séance libre.
 * - `importHistory()` : la reprise d'historique (`app:intervals:import`). Plage
 *   choisie, tous les lots d'affilée, séances libres pour ce qui n'était pas
 *   planifié, dry-run possible.
 *
 * **Rejouable.** L'unicité (`source`, `externalId`) est la garantie ; la liste
 * des identifiants connus est lue en une requête avant tout appel coûteux, donc
 * relancer ne réimporte rien et ne redemande aucun stream.
 *
 * **Fenêtre glissante avec recouvrement** (web). On relit depuis `syncedThrough
 * − 7 jours`, pas depuis `syncedThrough` : une montre synchronisée en retard
 * dépose dans Intervals, aujourd'hui, une sortie datée d'avant-hier. Sans
 * recouvrement, elle tomberait derrière la fenêtre. Première synchro : 30 jours.
 *
 * **Lot borné** (web). Chaque activité coûte un appel de streams. On en traite au
 * plus `BATCH` par clic, des plus anciennes aux plus récentes, et
 * `syncedThrough` n'avance que jusqu'à la dernière **traitée**.
 */
final class IntervalsImporter
{
    public const int BATCH = 40;

    private const int FIRST_SYNC_DAYS = 30;

    private const int OVERLAP_DAYS = 7;

    /** Vrai pendant une reprise d'historique réelle : c'est elle seule qui doit ralentir. */
    private bool $pacing = false;

    public function __construct(
        private readonly IntervalsConnectionRepository $connections,
        private readonly ImportedActivityRepository $activities,
        private readonly IntervalsClient $client,
        private readonly SecretBox $secretBox,
        private readonly ImportedActivityMapper $mapper,
        private readonly ActivityStreamAnalyzer $analyzer,
        private readonly ActivityMatcher $matcher,
        private readonly EntityManagerInterface $entityManager,
        /**
         * Pause entre deux appels de streams pendant une reprise d'historique. Le
         * quota d'une clé est de 2 500 requêtes par 15 minutes, soit 2,7 par
         * seconde : 400 ms le tiennent avec une marge, et évitent le plafond de
         * 10 par seconde. Paramètre et non constante pour que les tests passent 0.
         */
        private readonly int $historyPauseMicroseconds = 400_000,
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
        $connection = $this->connectionOf($user);
        $today ??= new \DateTimeImmutable('today');
        $apiKey = $this->secretBox->open($connection->getSealedApiKey());

        $oldest = null === $connection->getSyncedThrough()
            ? $today->modify(\sprintf('-%d days', self::FIRST_SYNC_DAYS))
            : $connection->getSyncedThrough()->modify(\sprintf('-%d days', self::OVERLAP_DAYS));

        $fresh = $this->freshPayloads($apiKey, $oldest, $today);
        $counts = $this->emptyCounts();
        $lastDate = null;
        $this->matcher->reset();

        try {
            foreach ($fresh as $payload) {
                if ($counts['imported'] >= self::BATCH) {
                    break;
                }

                $activity = $this->importOne($user, $apiKey, $payload, $counts, freeSession: false, dryRun: false);
                $lastDate = $activity?->getLocalDate() ?? $lastDate;
            }
        } finally {
            $remaining = \count($fresh) - $counts['processed'];

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

        return $this->report($counts, $remaining);
    }

    /**
     * Reprise d'historique sur une plage de dates locales, bornes comprises, en
     * enchaînant les lots. Écrit tous les `BATCH` activités : une panne ou un
     * quota épuisé en cours de route laisse en base tout ce qui précède, et une
     * relance reprend sans doublon.
     *
     * En **dry-run**, le même chemin est suivi sans demander de streams (le
     * quota) et sans rien écrire : le rapprochement est réellement calculé contre
     * la base, puis tout est détaché. Ce qui est annoncé est ce qui se produira,
     * aux zones et splits près.
     *
     * `syncedThrough` n'est **jamais reculé** : importer 2025 ne doit pas renvoyer
     * le bouton du web relire toute l'année à chaque clic. Il n'avance que si la
     * plage va jusqu'à aujourd'hui et a été entièrement traitée.
     *
     * @param callable(int $done, int $total): void|null $progress
     *
     * @throws IntervalsAuthException
     * @throws IntervalsUnavailableException
     */
    public function importHistory(
        User $user,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
        bool $freeSessions,
        bool $dryRun,
        ?callable $progress = null,
    ): SyncReport {
        $connection = $this->connectionOf($user);
        $apiKey = $this->secretBox->open($connection->getSealedApiKey());
        $fresh = $this->freshPayloads($apiKey, $since, $until);
        $counts = $this->emptyCounts();
        $this->matcher->reset();
        $completed = false;
        $this->pacing = !$dryRun;

        try {
            foreach ($fresh as $payload) {
                $this->importOne($user, $apiKey, $payload, $counts, $freeSessions, $dryRun);

                if (null !== $progress) {
                    $progress($counts['processed'], \count($fresh));
                }

                if (!$dryRun && 0 === $counts['imported'] % self::BATCH) {
                    $this->entityManager->flush();
                }
            }

            $completed = true;
        } finally {
            $this->pacing = false;

            if ($dryRun) {
                $this->entityManager->clear();
            } else {
                $today = new \DateTimeImmutable('today');

                if ($completed && $until >= $today && (null === $connection->getSyncedThrough() || $connection->getSyncedThrough() < $today)) {
                    $connection->setSyncedThrough($today);
                }

                $this->entityManager->flush();
            }
        }

        return $this->report($counts, \count($fresh) - $counts['processed']);
    }

    /**
     * Le chemin commun : mapper, enrichir, persister, rapprocher, compter.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, int>    $counts
     */
    private function importOne(User $user, string $apiKey, array $payload, array &$counts, bool $freeSession, bool $dryRun): ?ImportedActivity
    {
        $activity = $this->mapper->fromIntervals($user, $payload);

        if (null === $activity) {
            ++$counts['skipped'];
            ++$counts['processed'];

            return null;
        }

        if (!$dryRun) {
            $this->enrich($user, $apiKey, $activity);
        }

        $this->entityManager->persist($activity);
        ++$counts['imported'];
        ++$counts['processed'];

        match ($this->matcher->autoAttach($activity, $freeSession)) {
            ActivityMatch::ATTACHED => ++$counts['attached'],
            ActivityMatch::FREE_SESSION => ++$counts['freeSessions'],
            ActivityMatch::AMBIGUOUS => ++$counts['ambiguous'],
            ActivityMatch::NO_CANDIDATE, ActivityMatch::NOT_MATCHABLE => null,
        };

        return $activity;
    }

    /**
     * Les activités de la plage que Kadens ne connaît pas encore, de la plus
     * ancienne à la plus récente (l'API rend l'ordre inverse).
     *
     * @return list<array<string, mixed>>
     */
    private function freshPayloads(string $apiKey, \DateTimeImmutable $oldest, \DateTimeImmutable $newest): array
    {
        $payloads = $this->client->activities($apiKey, $oldest, $newest);

        usort($payloads, static fn (array $a, array $b): int => strcmp((string) ($a['start_date_local'] ?? ''), (string) ($b['start_date_local'] ?? '')));

        $ids = array_values(array_filter(array_map(
            static fn (array $p): ?string => isset($p['id']) && \is_scalar($p['id']) ? (string) $p['id'] : null,
            $payloads,
        )));
        $known = array_flip($this->activities->knownExternalIds(ActivitySource::INTERVALS, $ids));

        return array_values(array_filter(
            $payloads,
            static fn (array $p): bool => isset($p['id']) && \is_scalar($p['id']) && !isset($known[(string) $p['id']]),
        ));
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

        // Seul le web s'en passe : un clic ne traite que BATCH activités, bien
        // en dessous du plafond. Une reprise d'historique en enchaîne des
        // centaines et doit, elle, tenir le rythme autorisé.
        if ($this->pacing && $this->historyPauseMicroseconds > 0) {
            usleep($this->historyPauseMicroseconds);
        }
    }

    private function connectionOf(User $user): IntervalsConnection
    {
        return $this->connections->findForOwner($user)
            ?? throw new \LogicException('Aucun compte Intervals.icu connecté.');
    }

    /**
     * @return array{imported: int, attached: int, skipped: int, processed: int, freeSessions: int, ambiguous: int}
     */
    private function emptyCounts(): array
    {
        return ['imported' => 0, 'attached' => 0, 'skipped' => 0, 'processed' => 0, 'freeSessions' => 0, 'ambiguous' => 0];
    }

    /**
     * @param array<string, int> $counts
     */
    private function report(array $counts, int $remaining): SyncReport
    {
        return new SyncReport($counts['imported'], $counts['attached'], $counts['skipped'], $remaining, $counts['freeSessions'], $counts['ambiguous']);
    }
}
