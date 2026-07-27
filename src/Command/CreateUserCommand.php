<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création d'un compte. L'app n'a pas d'inscription publique (usage perso /
 * cercle restreint) : la porte d'entrée est cette commande, et les rôles
 * au-delà de ROLE_USER se donnent ensuite via `app:user:promote[-coach]`.
 *
 * Le mot de passe se saisit en mode masqué si l'argument est omis (évite de le
 * laisser traîner dans l'historique du shell).
 */
#[AsCommand(
    name: 'app:user:create',
    description: "Crée un User de base (email + mot de passe, rôle ROLE_USER).",
)]
final class CreateUserCommand extends Command
{
    /**
     * Longueur minimale du mot de passe. Doit rester alignée avec
     * `App\Form\ChangePasswordType` (changement de mot de passe depuis le profil).
     */
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'Email du compte (sert d\'identifiant de connexion)',
            )
            ->addArgument(
                'password',
                InputArgument::OPTIONAL,
                'Mot de passe en clair (demandé en saisie masquée si omis)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = trim((string) $input->getArgument('email'));

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Email invalide : %s', $email));

            return Command::INVALID;
        }

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            $io->error(sprintf('Un User existe déjà pour l\'email : %s', $email));

            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if (null === $password) {
            $password = $this->askPassword($io, $input);
            if (null === $password) {
                return Command::INVALID;
            }
        }

        if (\strlen((string) $password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $user = (new User())->setEmail($email);
        // getRoles() ajoute toujours ROLE_USER : on ne stocke rien de plus.
        $user->setRoles([]);
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Compte créé : %s (ROLE_USER).', $email));
        $io->comment('Pour en faire un coach ou un admin : `app:user:promote-coach` / `app:user:promote`.');

        return Command::SUCCESS;
    }

    /**
     * Saisie masquée + confirmation. Retourne null si la saisie est impossible
     * (mode non interactif) ou si les deux saisies diffèrent.
     */
    private function askPassword(SymfonyStyle $io, InputInterface $input): ?string
    {
        if (!$input->isInteractive()) {
            $io->error('Mode non interactif : passe le mot de passe en second argument.');

            return null;
        }

        $password = (string) $io->askHidden('Mot de passe');
        $confirm = (string) $io->askHidden('Répéter le mot de passe');

        if ($password !== $confirm) {
            $io->error('Les deux saisies ne correspondent pas.');

            return null;
        }

        return $password;
    }
}
