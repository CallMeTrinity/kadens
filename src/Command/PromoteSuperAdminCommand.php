<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ROLE_SUPER_ADMIN ouvre le back-office `/admin` (messages programmés). Il est
 * au-dessus de ROLE_ADMIN dans l'intention, pas dans la mécanique : les rôles du
 * projet sont plats, un ROLE_ADMIN n'y entre pas. Comme les autres promotions,
 * c'est un acte d'administration, d'où la commande (calquée sur
 * app:user:promote-coach).
 */
#[AsCommand(
    name: 'app:user:promote-super-admin',
    description: "Promeut un User (identifié par son email) au rôle ROLE_SUPER_ADMIN.",
)]
final class PromoteSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'Email du User à promouvoir super admin',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('Aucun User trouvé pour l\'email : %s', $email));

            return Command::FAILURE;
        }

        if ($user->isSuperAdmin()) {
            $io->info(sprintf('%s est déjà super admin.', $email));

            return Command::SUCCESS;
        }

        // getRoles() rajoute toujours ROLE_USER : on le retire pour ne pas le stocker en base.
        $roles = array_filter($user->getRoles(), static fn (string $role): bool => $role !== 'ROLE_USER');
        $roles[] = 'ROLE_SUPER_ADMIN';
        $user->setRoles(array_values(array_unique($roles)));

        $this->em->flush();

        $io->success(sprintf('%s est maintenant super admin (ROLE_SUPER_ADMIN) et accède à /admin.', $email));

        return Command::SUCCESS;
    }
}
