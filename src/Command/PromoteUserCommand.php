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

#[AsCommand(
    name: 'app:user:promote',
    description: "Promeut un User (identifié par son email) au rôle ROLE_ADMIN.",
)]
final class PromoteUserCommand extends Command
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
                'Email du User à promouvoir administrateur',
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

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $io->info(sprintf('%s est déjà administrateur.', $email));

            return Command::SUCCESS;
        }

        // getRoles() rajoute toujours ROLE_USER : on le retire pour ne pas le stocker en base.
        $roles = array_filter($user->getRoles(), static fn (string $role): bool => $role !== 'ROLE_USER');
        $roles[] = 'ROLE_ADMIN';
        $user->setRoles(array_values(array_unique($roles)));

        $this->em->flush();

        $io->success(sprintf('%s est maintenant administrateur (ROLE_ADMIN).', $email));

        return Command::SUCCESS;
    }
}
