<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Exercise;
use App\Entity\User;
use App\Repository\ExerciseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bascule dans la bibliothèque **globale** les exercices perso appartenant à un
 * ROLE_ADMIN. C'est un rattrapage : la règle « un admin qui crée un exercice le
 * crée global » (`ExerciseController::new`) ne vaut que depuis qu'elle existe,
 * et tout ce qu'un admin s'était créé avant est resté perso — invisible pour les
 * autres comptes alors qu'il avait vocation à alimenter la globale.
 *
 * **Les identifiants ne bougent pas.** La bascule est un `UPDATE owner_id =
 * NULL`, jamais une copie suivie d'une suppression : tout ce qui pointe déjà
 * l'exercice (`PrescribedExercise`, l'historique de performance, le cache local
 * du mobile qui indexe sur l'id serveur) continue de le trouver. C'est aussi ce
 * qui interdit de « fusionner » un doublon — cf. plus bas.
 *
 * Deux conséquences voulues, à ne pas prendre pour des effets de bord :
 * - `updatedAt` est réécrit par le `preUpdate` de l'entité, donc les exercices
 *   basculés entrent dans le prochain delta de `GET /api/bootstrap` et
 *   descendent sur les téléphones sans qu'il faille purger quoi que ce soit ;
 * - l'exercice devient éditable/supprimable par **tout** ROLE_ADMIN et lisible
 *   par tout le monde (`ExerciseVoter`). C'est le sens de l'opération.
 *
 * **Un nom déjà porté par la globale est ignoré, pas fusionné.** Fusionner
 * voudrait dire repointer les prescriptions vers l'exercice global puis
 * supprimer celui de l'admin : ce serait précisément casser un id. La commande
 * les liste à part et laisse l'arbitrage à la main.
 *
 * Écriture sur demande explicite (`--force`), comme `app:log:backfill` : sans
 * elle, la commande montre ce qu'elle ferait et sort.
 */
#[AsCommand(
    name: 'app:exercise:globalize',
    description: 'Bascule les exercices perso des administrateurs dans la bibliothèque globale (owner = null), sans toucher aux identifiants.',
)]
final class GlobalizeAdminExercisesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExerciseRepository $exercises,
        private readonly UserRepository $users,
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
                "Ne traiter que cet administrateur (email) — défaut : tous les ROLE_ADMIN",
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Écrire réellement la bascule (sans cette option, la commande se contente de lister)",
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $admins = $this->resolveAdmins($input, $io);
        if (null === $admins) {
            return Command::INVALID;
        }

        if ([] === $admins) {
            $io->success('Aucun administrateur trouvé : rien à basculer.');

            return Command::SUCCESS;
        }

        // Le tri secondaire sur l'id n'est pas cosmétique : quand deux admins
        // portent le même nom en perso, il désigne celui qui gagne la place dans
        // la globale. Sans lui, SQL départage comme il veut, et deux exécutions
        // de la même base ne publieraient pas le même exercice. Le plus ancien
        // gagne — c'est celui que les autres prescriptions citent déjà le plus.
        /** @var Exercise[] $candidates */
        $candidates = $this->exercises->findBy(['owner' => $admins], ['name' => 'ASC', 'id' => 'ASC']);

        if ([] === $candidates) {
            $io->success('Aucun exercice perso chez les administrateurs : rien à basculer.');

            return Command::SUCCESS;
        }

        // L'index part de la globale existante et se remplit au fil de la boucle :
        // deux admins peuvent porter le même nom en perso, et le second est alors
        // un doublon du premier — pas du contenu de la base au démarrage.
        $globalNames = $this->exercises->globalNameIndex();

        $rows = [];
        $toGlobalize = [];
        $conflicts = 0;

        foreach ($candidates as $exercise) {
            $name = (string) $exercise->getName();
            $key = mb_strtolower(trim($name));
            $id = (int) $exercise->getId();
            $ownerEmail = $exercise->getOwner()?->getEmail() ?? '—';

            if (isset($globalNames[$key])) {
                $rows[] = [$id, $name, $ownerEmail, \sprintf('doublon de #%d — ignoré', $globalNames[$key])];
                ++$conflicts;
                continue;
            }

            $globalNames[$key] = $id;
            $rows[] = [$id, $name, $ownerEmail, 'à basculer'];
            $toGlobalize[] = $exercise;
        }

        $io->table(['#', 'Exercice', 'Propriétaire actuel', 'Action'], $rows);

        $summary = \sprintf(
            '%d exercice(s) à basculer, %d doublon(s) ignoré(s).',
            \count($toGlobalize),
            $conflicts,
        );

        if ([] === $toGlobalize) {
            $io->warning('Rien à basculer — ' . $summary);

            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            $io->warning('Simulation : rien n\'a été écrit. Relancer avec --force pour appliquer.');
            $io->text($summary);

            return Command::SUCCESS;
        }

        foreach ($toGlobalize as $exercise) {
            $exercise->setOwner(null);
        }

        $this->em->flush();

        $io->success('Bascule effectuée (identifiants inchangés) — ' . $summary);

        return Command::SUCCESS;
    }

    /**
     * Les administrateurs à traiter, ou `null` si l'entrée est invalide.
     *
     * Le filtre se fait en PHP et non en SQL : `roles` est une colonne JSON, et
     * un `LIKE '%ROLE_ADMIN%'` attraperait un hypothétique `ROLE_ADMIN_X`. La
     * table des comptes est de toute façon minuscule — ils se créent en console.
     *
     * @return list<User>|null
     */
    private function resolveAdmins(InputInterface $input, SymfonyStyle $io): ?array
    {
        /** @var string|null $email */
        $email = $input->getOption('user');

        if (null !== $email) {
            $user = $this->users->findOneBy(['email' => $email]);

            if (!$user instanceof User) {
                $io->error(\sprintf('Aucun compte pour « %s ».', $email));

                return null;
            }

            if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $io->error(\sprintf('« %s » n\'est pas administrateur : ses exercices lui appartiennent.', $email));

                return null;
            }

            return [$user];
        }

        return array_values(array_filter(
            $this->users->findAll(),
            static fn (User $user): bool => \in_array('ROLE_ADMIN', $user->getRoles(), true),
        ));
    }
}
