<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\ExerciseRepository;
use App\Repository\UserRepository;
use App\Service\BlastCsvParser;
use App\Service\BlastExerciseMap;
use App\Service\BlastImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reprise d'un historique d'entraînement exporté en CSV par Blast.
 *
 * ## Le déroulé, qui n'est pas en une fois
 *
 * 1. `--report` sort les clés d'exercices non tranchées, avec des candidats de
 *    la bibliothèque et le volume de séries que chacune représente ;
 * 2. on complète `data/blast-exercise-map.json` à la main (`--write-map` en
 *    pose le squelette) ;
 * 3. un passage sans `--force` montre ce qui serait écrit ;
 * 4. `--force` écrit.
 *
 * Les étapes 1 et 2 sont l'essentiel du travail, et elles ne sont pas
 * automatisables : voir `BlastExerciseMap`.
 *
 * ## Pourquoi le dry-run est le défaut
 *
 * Même raison que `app:log:backfill` : la commande **fabrique du fait**. Ce
 * qu'elle écrit devient indiscernable d'un réalisé venu du téléphone, et alimente
 * records et trajectoires. Autant la regarder travailler avant de la laisser
 * faire. Le dry-run emprunte exactement le même chemin que l'écriture et se
 * termine par un `clear()` plutôt qu'un `flush()` : ce qu'il annonce est ce qui
 * se produira.
 */
#[AsCommand(
    name: 'app:import:blast',
    description: 'Importe un historique de séances exporté en CSV par Blast, en séances datées « Faite ».',
)]
final class ImportBlastWorkoutsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ExerciseRepository $exercises,
        private readonly BlastCsvParser $parser,
        private readonly BlastImporter $importer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY, 'Fichiers CSV à importer (défaut : data/*-blast-workouts-export.csv)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email du propriétaire des séances importées')
            ->addOption('map', null, InputOption::VALUE_REQUIRED, 'Fichier de correspondance des exercices (défaut : data/blast-exercise-map.json)')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Fuseau dans lequel l\'export a horodaté ses séances', BlastCsvParser::DEFAULT_TIMEZONE)
            ->addOption('report', null, InputOption::VALUE_NONE, 'Lister les clés non tranchées avec des candidats, et sortir')
            ->addOption('write-map', null, InputOption::VALUE_NONE, 'Écrire le squelette du fichier de correspondance (avec --report)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Écrire réellement (sans cette option, la commande se contente de montrer)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $email */
        $email = $input->getOption('user');
        $owner = null === $email ? null : $this->users->findOneBy(['email' => $email]);

        if (!$owner instanceof User) {
            $io->error(null === $email ? 'L\'option --user est requise.' : \sprintf('Aucun compte pour « %s ».', $email));

            return Command::INVALID;
        }

        /** @var list<string> $files */
        $files = $input->getArgument('files');
        $files = [] !== $files ? $files : (glob($this->projectDir . '/data/*-blast-workouts-export.csv') ?: []);

        if ([] === $files) {
            $io->error('Aucun fichier à importer.');

            return Command::INVALID;
        }

        /** @var string|null $mapOption */
        $mapOption = $input->getOption('map');
        $mapFile = $mapOption ?? $this->projectDir . '/data/blast-exercise-map.json';

        /** @var string $timezone */
        $timezone = $input->getOption('timezone');

        try {
            $sessions = [];

            foreach ($files as $file) {
                $parsed = $this->parser->parse($file, $timezone);
                $io->text(\sprintf('%s : %d séances', basename($file), \count($parsed)));
                $sessions = [...$sessions, ...$parsed];
            }

            $map = BlastExerciseMap::load($mapFile, $owner, $this->exercises, $this->em);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        usort($sessions, static fn (array $a, array $b): int => $a['startedAt'] <=> $b['startedAt']);

        if (true === $input->getOption('report')) {
            return $this->report($io, $sessions, $map, $mapFile, true === $input->getOption('write-map'));
        }

        $unresolved = $this->unresolved($sessions, $map);

        if ([] !== $unresolved) {
            $io->error(\sprintf(
                '%d clé(s) d\'exercice non tranchée(s) dans %s. Lancez la commande avec --report pour les lister.',
                \count($unresolved),
                basename($mapFile),
            ));

            return Command::FAILURE;
        }

        return $this->apply($io, $sessions, $map, true === $input->getOption('force'));
    }

    /**
     * Le passage d'import, en dry-run ou pour de vrai.
     *
     * @param list<array<string, mixed>> $sessions
     */
    private function apply(SymfonyStyle $io, array $sessions, BlastExerciseMap $map, bool $force): int
    {
        /** @var list<string> $sourceKeys */
        $sourceKeys = array_map(static fn (array $s): string => (string) $s['sourceKey'], $sessions);
        $alreadyThere = \count($this->importer->existingFor($sourceKeys));

        if ($force && $alreadyThere > 0) {
            // Effacer avant d'écrire, et dans un flush séparé : les uuid étant
            // déterministes, réécrire au même identifiant dans le même flush
            // violerait l'unicité (cf. l'en-tête de `BlastImporter`).
            $this->importer->purge($sourceKeys);
        }

        $written = 0;
        $skipped = 0;
        $exercises = 0;
        $sets = 0;

        try {
            foreach ($sessions as $session) {
                /** @var array{sourceKey: string, title: string, startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable|null, date: \DateTimeImmutable, entries: list<array{key: string, name: string, equipment: string, execution: string, sets: list<array{setType: \App\Enum\SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}>}>} $session */
                $result = $this->importer->import($session, $map);

                if (null === $result) {
                    ++$skipped;

                    continue;
                }

                ++$written;
                $exercises += $result['exercises'];
                $sets += $result['sets'];
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            $this->em->clear();

            return Command::FAILURE;
        }

        $created = $map->createdExercises();
        $range = $this->range($sessions);

        $io->definitionList(
            ['Période' => $range],
            ['Séances écrites' => (string) $written],
            ['Séances sans réalisé (ignorées)' => (string) $skipped],
            ['Exercices réalisés' => (string) $exercises],
            ['Séries' => (string) $sets],
            ['Exercices perso créés' => (string) \count($created)],
            ['Séances déjà importées (remplacées)' => (string) $alreadyThere],
        );

        if ([] !== $created) {
            $io->section('Exercices créés dans la bibliothèque perso');
            $io->listing(array_map(static fn ($e): string => (string) $e->getName(), $created));
        }

        if (!$force) {
            // Rien n'a été flushé : `clear()` détache tout ce que le passage a
            // construit, y compris les exercices persistés par le mapping.
            $this->em->clear();
            $io->warning('Rien n\'a été écrit. Relancez avec --force pour appliquer.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(\sprintf('%d séances importées chez %s.', $written, (string) $map->getOwner()->getEmail()));

        return Command::SUCCESS;
    }

    /**
     * Les clés non tranchées, avec des candidats et le volume qu'elles pèsent.
     *
     * @param list<array<string, mixed>> $sessions
     */
    private function report(SymfonyStyle $io, array $sessions, BlastExerciseMap $map, string $mapFile, bool $write): int
    {
        $unresolved = $this->unresolved($sessions, $map);

        if ([] === $unresolved) {
            $io->success(\sprintf('Toutes les clés sont tranchées dans %s.', basename($mapFile)));

            return Command::SUCCESS;
        }

        // Le plus gros volume d'abord : c'est là que l'effort de mapping paie.
        arsort($unresolved);

        $rows = [];
        $skeleton = [];

        foreach ($unresolved as $key => $count) {
            $label = explode('|', $key)[0];
            $suggestions = $map->suggestionsFor($label);

            $rows[] = [$count, $key, implode("\n", $suggestions) ?: '—'];
            $skeleton[$key] = BlastExerciseMap::UNRESOLVED;
        }

        $io->section(\sprintf('%d clé(s) à trancher, %d séries concernées', \count($unresolved), array_sum($unresolved)));
        $io->table(['Séries', 'Clé de l\'export', 'Candidats dans la bibliothèque'], $rows);

        if ($write) {
            $existing = is_file($mapFile) ? (array) json_decode((string) file_get_contents($mapFile), true) : [];
            $merged = $existing + $skeleton;
            $json = json_encode($merged, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            if (false === $json || false === file_put_contents($mapFile, $json . "\n")) {
                $io->error(\sprintf('Impossible d\'écrire %s.', $mapFile));

                return Command::FAILURE;
            }

            $io->success(\sprintf('Squelette écrit dans %s : %d clé(s) à remplir.', basename($mapFile), \count($skeleton)));
        }

        return Command::SUCCESS;
    }

    /**
     * Les clés non tranchées et le nombre de séries que chacune porte.
     *
     * @param list<array<string, mixed>> $sessions
     *
     * @return array<string, int>
     */
    private function unresolved(array $sessions, BlastExerciseMap $map): array
    {
        $counts = [];

        foreach ($sessions as $session) {
            /** @var list<array{key: string, sets: list<mixed>}> $entries */
            $entries = $session['entries'];

            foreach ($entries as $entry) {
                if ('unresolved' !== $map->inspect($entry['key'])['status']) {
                    continue;
                }

                $counts[$entry['key']] = ($counts[$entry['key']] ?? 0) + \count($entry['sets']);
            }
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $sessions */
    private function range(array $sessions): string
    {
        if ([] === $sessions) {
            return '—';
        }

        /** @var \DateTimeImmutable $first */
        $first = $sessions[0]['date'];
        /** @var \DateTimeImmutable $last */
        $last = $sessions[array_key_last($sessions)]['date'];

        return \sprintf('%s → %s', $first->format('d/m/Y'), $last->format('d/m/Y'));
    }
}
