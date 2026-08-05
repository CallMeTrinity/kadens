<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\UserRepository;
use App\Service\LogBackfiller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattrape le réalisé des séances de muscu faites **avant** l'app mobile :
 * chaque séance passée cochée « Faite » et vide de réalisé reçoit la copie de
 * son prescrit, série par série. Elles entrent alors dans les statistiques —
 * `LogMetrics`, `PerformanceHistory`, `ExerciseTrajectory` — au lieu de n'être
 * qu'un statut au calendrier.
 *
 * **Elle n'est pas idempotente au sens strict, mais elle est rejouable** : une
 * séance déjà traitée porte un réalisé, donc elle n'est plus candidate. Relancer
 * la commande ne double rien ; en revanche, effacer le réalisé d'une séance la
 * rend à nouveau éligible.
 *
 * Écriture **sur demande explicite** (`--force`) : sans elle, la commande liste
 * ce qu'elle ferait et sort. La règle du projet est que le réalisé est un fait,
 * et cette commande en fabrique — autant la regarder travailler avant.
 */
#[AsCommand(
    name: 'app:log:backfill',
    description: 'Déduit le réalisé des séances de muscu passées cochées « Faite » à partir de leur prescrit.',
)]
final class BackfillLogsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly UserRepository $users,
        private readonly LogBackfiller $backfiller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'Email du propriétaire à traiter (défaut : tous les comptes)',
            )
            ->addOption(
                'since',
                null,
                InputOption::VALUE_REQUIRED,
                'Ne traiter que les séances à partir de cette date (AAAA-MM-JJ)',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Écrire réellement le réalisé (sans cette option, la commande se contente de lister)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $email */
        $email = $input->getOption('user');
        $owner = null;

        if (null !== $email) {
            $owner = $this->users->findOneBy(['email' => $email]);

            if (!$owner instanceof User) {
                $io->error(\sprintf('Aucun compte pour « %s ».', $email));

                return Command::INVALID;
            }
        }

        /** @var string|null $sinceOption */
        $sinceOption = $input->getOption('since');
        $since = null;

        if (null !== $sinceOption) {
            // `!` remet l'heure à zéro : sans lui, la date porterait l'heure
            // courante et écarterait les séances du jour de la borne.
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $sinceOption);

            if (false === $parsed) {
                $io->error('La date de --since doit être au format AAAA-MM-JJ.');

                return Command::INVALID;
            }

            $since = $parsed;
        }

        $today = new \DateTimeImmutable('today');
        $candidates = $this->scheduled->findDoneWithoutLog($owner, $since, $today);

        $rows = [];
        $written = [];
        $totalExercises = 0;
        $totalSets = 0;

        foreach ($candidates as $scheduled) {
            $logged = $this->backfiller->build($scheduled);

            if ([] === $logged) {
                // Séance faite sans une seule ligne de force : rien à en tirer,
                // et rien à en dire non plus (une sortie course en fait partie).
                continue;
            }

            $sets = 0;
            foreach ($logged as $entry) {
                $sets += $entry->getLoggedSets()->count();
            }

            $rows[] = [
                $scheduled->getScheduledDate()?->format('d/m/Y') ?? '—',
                $this->titleOf($scheduled),
                $scheduled->getOwner()?->getEmail() ?? '—',
                \count($logged),
                $sets,
            ];

            $totalExercises += \count($logged);
            $totalSets += $sets;
            $written[] = [$scheduled, $logged];
        }

        if ([] === $rows) {
            $io->success('Aucune séance à rattraper.');

            return Command::SUCCESS;
        }

        $io->table(['Date', 'Séance', 'Compte', 'Exercices', 'Séries'], $rows);

        $summary = \sprintf(
            '%d séance(s), %d exercice(s), %d série(s).',
            \count($rows),
            $totalExercises,
            $totalSets,
        );

        if (!$input->getOption('force')) {
            $io->warning('Simulation : rien n\'a été écrit. Relancer avec --force pour appliquer.');
            $io->text($summary);

            return Command::SUCCESS;
        }

        foreach ($written as [$scheduled, $logged]) {
            foreach ($logged as $entry) {
                $scheduled->addLoggedExercise($entry);
            }
        }

        // Un seul flush : la cascade `persist` de `loggedExercises` porte les
        // exercices et leurs séries, il n'y a rien à persister à la main.
        $this->em->flush();

        $io->success('Réalisé écrit — ' . $summary);

        return Command::SUCCESS;
    }

    /**
     * Le titre vivant de la séance source, ou le snapshot posé à la pose. Même
     * ordre de préférence que `getDisplayTitle()` côté affichage.
     */
    private function titleOf(ScheduledWorkout $scheduled): string
    {
        return $scheduled->getWorkout()?->getTitle() ?? $scheduled->getTitle() ?? 'Sans titre';
    }
}
