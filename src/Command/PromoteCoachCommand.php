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
 * ROLE_COACH n'est pas auto-attribuable : on ne veut pas qu'un utilisateur se
 * déclare coach pour aller démarcher les autres. C'est un acte d'administration,
 * d'où la commande (calquée sur app:user:promote).
 */
#[AsCommand(
    name: 'app:user:promote-coach',
    description: "Promeut un User (identifié par son email) au rôle ROLE_COACH.",
)]
final class PromoteCoachCommand extends Command
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
                'Email du User à promouvoir coach',
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

        if ($user->isCoach()) {
            $io->info(sprintf('%s est déjà coach.', $email));

            return Command::SUCCESS;
        }

        // getRoles() rajoute toujours ROLE_USER : on le retire pour ne pas le stocker en base.
        $roles = array_filter($user->getRoles(), static fn (string $role): bool => $role !== 'ROLE_USER');
        $roles[] = 'ROLE_COACH';
        $user->setRoles(array_values(array_unique($roles)));

        $this->em->flush();

        $io->success(sprintf('%s est maintenant coach (ROLE_COACH) et peut suivre des athlètes.', $email));

        return Command::SUCCESS;
    }
}
