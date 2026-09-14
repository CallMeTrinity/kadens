<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\IntervalsConnectionRepository;
use App\Repository\UserRepository;
use App\Service\IntervalsAuthException;
use App\Service\IntervalsImporter;
use App\Service\IntervalsUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprise de l'historique cardio depuis Intervals.icu, au-delà de la fenêtre du
 * bouton « Synchroniser » (30 jours à la première synchro, puis glissante).
 *
 * Utilise la clé que l'utilisateur a collée dans `/profile/settings` : pas de
 * connexion, pas d'import. Les activités sans séance prévue deviennent des
 * **séances libres** — c'est la différence avec le bouton du web, où le cardio
 * est censé être planifié (cf. `ActivityMatcher`). `--no-free-sessions` les
 * laisse à rattacher.
 *
 * **Dry-run par défaut**, comme les reprises de salle : la commande fabrique du
 * fait (séances faites, volumes d'endurance), autant la regarder avant. Le
 * dry-run ne demande aucun stream et n'écrit rien ; `--force` écrit.
 *
 * **Relançable.** Une panne ou un quota épuisé laisse en base ce qui a été
 * traité (écriture par lots) ; relancer la même commande reprend sans doublon.
 */
#[AsCommand(
    name: 'app:intervals:import',
    description: 'Importe l\'historique des activités Intervals.icu d\'un utilisateur sur une plage de dates.',
)]
final class ImportIntervalsHistoryCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly IntervalsConnectionRepository $connections,
        private readonly IntervalsImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte Kadens (sa clé Intervals doit être enregistrée)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Première date locale incluse, AAAA-MM-JJ')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Dernière date locale incluse, AAAA-MM-JJ (défaut : aujourd\'hui)')
            ->addOption('no-free-sessions', null, InputOption::VALUE_NONE, 'Ne pas créer de séance libre pour une activité sans séance prévue')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Écrire réellement (sans cette option, la commande se contente de montrer)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        $user = $this->users->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $io->error(\sprintf('Aucun compte pour « %s ».', $email));

            return Command::INVALID;
        }

        if (null === $this->connections->findForOwner($user)) {
            $io->error('Ce compte n\'a pas de clé Intervals.icu. Colle-la d\'abord dans /profile/settings.');

            return Command::INVALID;
        }

        $since = $this->date($input->getOption('since'));
        $until = null === $input->getOption('until') ? new \DateTimeImmutable('today') : $this->date($input->getOption('until'));

        if (null === $since || null === $until || $since > $until) {
            $io->error('--since est requis, et les dates s\'écrivent AAAA-MM-JJ avec --since avant --until.');

            return Command::INVALID;
        }

        $force = true === $input->getOption('force');
        $freeSessions = true !== $input->getOption('no-free-sessions');

        $io->text(\sprintf(
            'Du %s au %s, séances libres %s%s.',
            $since->format('d/m/Y'),
            $until->format('d/m/Y'),
            $freeSessions ? 'activées' : 'désactivées',
            $force ? '' : ' (dry-run)',
        ));

        $bar = null;

        try {
            $report = $this->importer->importHistory(
                $user,
                $since,
                $until,
                $freeSessions,
                dryRun: !$force,
                progress: function (int $done, int $total) use ($io, &$bar): void {
                    $bar ??= $io->createProgressBar($total);
                    $bar->setProgress($done);
                },
            );
        } catch (IntervalsAuthException) {
            $io->error('Intervals.icu refuse la clé enregistrée. Colle une nouvelle clé dans /profile/settings.');

            return Command::FAILURE;
        } catch (IntervalsUnavailableException $e) {
            $io->newLine(2);
            $io->error($e->getMessage().' Ce qui a été traité est conservé : relance la même commande pour reprendre.');

            return Command::FAILURE;
        }

        $bar?->finish();
        $io->newLine(2);

        $io->definitionList(
            ['Activités nouvelles' => (string) $report->imported],
            ['Rattachées à une séance prévue' => (string) $report->attached],
            ['Séances libres créées' => (string) $report->freeSessions],
            ['À rattacher (plusieurs séances possibles)' => (string) $report->ambiguous],
            ['À rattacher (sans séance, ou type non reconnu)' => (string) ($report->imported - $report->attached - $report->freeSessions - $report->ambiguous)],
            ['Ignorées (arrivées via Strava)' => (string) $report->skipped],
        );

        if (!$force) {
            $io->warning('Rien n\'a été écrit, aucun stream demandé. Relance avec --force pour importer.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d activités importées pour %s.', $report->imported, $email));

        return Command::SUCCESS;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
