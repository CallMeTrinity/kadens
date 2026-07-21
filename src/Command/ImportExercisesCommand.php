<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Exercise;
use App\Enum\ActivityType;
use App\Enum\TargetArea;
use App\Repository\ExerciseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-exercises',
    description: "Importe des exercices dans la bibliothèque depuis un fichier JSON (ignore les doublons par nom).",
)]
final class ImportExercisesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly UserRepository $userRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Chemin du fichier JSON à importer (défaut : data/exercises.json)',
            )
            ->addOption(
                'owner',
                null,
                InputOption::VALUE_REQUIRED,
                "Email du User propriétaire des exercices (défaut : bibliothèque globale sans owner)",
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $fileArg */
        $fileArg = $input->getArgument('file');
        $file = $fileArg ?? $this->projectDir . '/data/exercises.json';

        if (!is_file($file)) {
            $io->error(sprintf('Fichier introuvable : %s', $file));

            return Command::FAILURE;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $io->error(sprintf('Impossible de lire le fichier : %s', $file));

            return Command::FAILURE;
        }

        try {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('JSON invalide : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!is_array($rows)) {
            $io->error('Le JSON racine doit être un tableau d\'exercices.');

            return Command::FAILURE;
        }

        $owner = null;
        if (\is_string($ownerEmail = $input->getOption('owner')) && $ownerEmail !== '') {
            $owner = $this->userRepository->findOneBy(['email' => $ownerEmail]);
            if ($owner === null) {
                $io->error(sprintf('Aucun User trouvé pour l\'email : %s', $ownerEmail));

                return Command::FAILURE;
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($rows as $index => $row) {
            $line = $index + 1;

            if (!is_array($row) || !isset($row['name']) || !is_string($row['name']) || trim($row['name']) === '') {
                $io->warning(sprintf('#%d : "name" manquant ou invalide, ignoré.', $line));
                ++$errors;
                continue;
            }

            $name = trim($row['name']);

            if ($this->exerciseRepository->findOneBy(['name' => $name]) !== null) {
                ++$skipped;
                continue;
            }

            $activity = ActivityType::tryFrom((string) ($row['activity'] ?? ''));
            if ($activity === null) {
                $io->warning(sprintf('#%d "%s" : activité inconnue ("%s"), ignoré.', $line, $name, (string) ($row['activity'] ?? '')));
                ++$errors;
                continue;
            }

            $targetAreas = [];
            foreach ((array) ($row['targetAreas'] ?? []) as $area) {
                $target = TargetArea::tryFrom((string) $area);
                if ($target === null) {
                    $io->warning(sprintf('#%d "%s" : zone inconnue ("%s"), ignorée.', $line, $name, (string) $area));
                    continue;
                }
                $targetAreas[] = $target;
            }

            $exercise = new Exercise();
            $exercise->setName($name);
            $exercise->setDescription(isset($row['description']) && is_string($row['description']) ? $row['description'] : null);
            $exercise->setActivity($activity);
            $exercise->setTargetAreas($targetAreas === [] ? null : $targetAreas);
            $exercise->setMediaUrl(isset($row['mediaUrl']) && is_string($row['mediaUrl']) ? $row['mediaUrl'] : null);
            $exercise->setOwner($owner);

            $this->em->persist($exercise);
            ++$created;
        }

        $this->em->flush();

        $io->success(sprintf(
            '%d exercice(s) créé(s), %d ignoré(s) (doublon), %d en erreur.',
            $created,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::INVALID : Command::SUCCESS;
    }
}
