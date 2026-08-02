<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DeletedEntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retire les pierres tombales trop vieilles pour servir (KL-14). Prévue pour le
 * cron du mutualisé, comme `app:pairing:purge`.
 *
 * La rétention par défaut est de **180 jours**, soit le double de la durée de vie
 * d'un `ApiToken` (90 jours d'expiration glissante) : un téléphone qui n'a pas
 * synchronisé depuis plus longtemps que ça n'a de toute façon plus de jeton
 * valide, il repartira d'un bootstrap complet — où les pierres tombales ne
 * servent pas, puisque le jeu complet remplace tout.
 */
#[AsCommand(
    name: 'app:deleted:purge',
    description: 'Supprime les pierres tombales antérieures à la rétention (delta du bootstrap mobile).',
)]
final class PurgeDeletedEntitiesCommand extends Command
{
    private const int DEFAULT_RETENTION_DAYS = 180;

    public function __construct(
        private readonly DeletedEntityRepository $deletedEntities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Rétention en jours',
            (string) self::DEFAULT_RETENTION_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');

        if ($days < 1) {
            $io->error('La rétention doit valoir au moins un jour.');

            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', $days));
        $deleted = $this->deletedEntities->deleteOlderThan($before);

        if (0 === $deleted) {
            $io->info(sprintf('Aucune pierre tombale antérieure au %s.', $before->format('d/m/Y')));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d pierre(s) tombale(s) supprimée(s).', $deleted));

        return Command::SUCCESS;
    }
}
