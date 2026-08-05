<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PairingCodeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vide la table des codes d'appairage échus (KL-46). Prévue pour le cron du
 * mutualisé, où il n'y a ni conteneur ni démon : une commande appelée
 * quotidiennement suffit largement pour des lignes qui vivent deux minutes.
 *
 * Un code périmé n'est pas dangereux — il n'est plus échangeable, son échéance
 * est dans la condition `WHERE` de la consommation. Il est juste inutile, et
 * rien d'autre dans l'app ne nettoie cette table.
 */
#[AsCommand(
    name: 'app:pairing:purge',
    description: "Supprime les codes d'appairage expirés (consommés ou non).",
)]
final class PurgePairingCodesCommand extends Command
{
    public function __construct(
        private readonly PairingCodeRepository $pairingCodes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->pairingCodes->deleteExpired();

        if (0 === $deleted) {
            $io->info('Aucun code d\'appairage expiré.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d code(s) d\'appairage expiré(s) supprimé(s).', $deleted));

        return Command::SUCCESS;
    }
}
