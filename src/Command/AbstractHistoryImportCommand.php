<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\ExerciseRepository;
use App\Repository\UserRepository;
use App\Service\ImportedExerciseMap;
use App\Service\TrainingHistoryImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le déroulé commun à toute reprise d'historique venue d'une autre application.
 * Ce qui change d'une source à l'autre tient en quatre points (le parseur, les
 * fichiers par défaut, le fichier de correspondance, le nom affiché) ; tout le
 * reste — les étapes, les garde-fous, le rapport — est identique et vit ici.
 *
 * ## Le déroulé, qui n'est pas en une fois
 *
 * 1. `--report` sort les clés d'exercices non tranchées, avec des candidats de
 *    la bibliothèque et le volume de séries que chacune représente ;
 * 2. on complète le fichier de correspondance à la main (`--write-map` en pose
 *    le squelette) ;
 * 3. un passage sans `--force` montre ce qui serait écrit ;
 * 4. `--force` écrit.
 *
 * Les étapes 1 et 2 sont l'essentiel du travail, et elles ne sont pas
 * automatisables : voir `ImportedExerciseMap`.
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
abstract class AbstractHistoryImportCommand extends Command
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ExerciseRepository $exercises,
        private readonly TrainingHistoryImporter $importer,
        protected readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Les séances d'un fichier, mises à plat par le parseur de la source.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function parseFile(string $file, string $timezone): array;

    /** Le fuseau par défaut de la source. */
    abstract protected function defaultTimezone(): string;

    /** Le motif des fichiers pris quand aucun n'est passé en argument. */
    abstract protected function defaultGlob(): string;

    /** Le fichier de correspondance par défaut, relatif au projet. */
    abstract protected function defaultMapFile(): string;

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY, \sprintf('Fichiers CSV à importer (défaut : %s)', $this->defaultGlob()))
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Email du propriétaire des séances importées')
            ->addOption('map', null, InputOption::VALUE_REQUIRED, \sprintf('Fichier de correspondance des exercices (défaut : %s)', $this->defaultMapFile()))
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Fuseau dans lequel l\'export a daté ses séances', $this->defaultTimezone())
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
        $files = [] !== $files ? $files : (glob($this->projectDir . '/' . $this->defaultGlob()) ?: []);

        if ([] === $files) {
            $io->error('Aucun fichier à importer.');

            return Command::INVALID;
        }

        /** @var string|null $mapOption */
        $mapOption = $input->getOption('map');
        $mapFile = $mapOption ?? $this->projectDir . '/' . $this->defaultMapFile();

        /** @var string $timezone */
        $timezone = $input->getOption('timezone');

        try {
            $sessions = [];

            foreach ($files as $file) {
                $parsed = $this->parseFile($file, $timezone);
                $io->text(\sprintf('%s : %d séances', basename($file), \count($parsed)));
                $sessions = [...$sessions, ...$parsed];
            }

            $map = ImportedExerciseMap::load($mapFile, $owner, $this->exercises, $this->em);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // `loggedAt` et pas `startedAt` : une source peut n'exporter aucune heure
        // (FitNotes), et `date` seule laisserait ex æquo deux séances du même jour.
        usort($sessions, static fn (array $a, array $b): int => $a['loggedAt'] <=> $b['loggedAt']);

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
    private function apply(SymfonyStyle $io, array $sessions, ImportedExerciseMap $map, bool $force): int
    {
        /** @var list<string> $sourceKeys */
        $sourceKeys = array_map(static fn (array $s): string => (string) $s['sourceKey'], $sessions);
        $alreadyThere = \count($this->importer->existingFor($sourceKeys));

        if ($force && $alreadyThere > 0) {
            // Effacer avant d'écrire, et dans un flush séparé : les uuid étant
            // déterministes, réécrire au même identifiant dans le même flush
            // violerait l'unicité (cf. l'en-tête de `TrainingHistoryImporter`).
            $this->importer->purge($sourceKeys);
        }

        $written = 0;
        $skipped = 0;
        $exercises = 0;
        $sets = 0;

        try {
            foreach ($sessions as $session) {
                /** @var array{sourceKey: string, title: string, startedAt: \DateTimeImmutable|null, endedAt: \DateTimeImmutable|null, loggedAt: \DateTimeImmutable, date: \DateTimeImmutable, entries: list<array{key: string, name: string, notes?: string|null, sets: list<array{setType: \App\Enum\SetType, reps: int|null, weightKg: float|null, durationSeconds: int|null}>}>} $session */
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

        $io->definitionList(
            ['Période' => $this->range($sessions)],
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
    private function report(SymfonyStyle $io, array $sessions, ImportedExerciseMap $map, string $mapFile, bool $write): int
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
            $skeleton[$key] = ImportedExerciseMap::UNRESOLVED;
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
    private function unresolved(array $sessions, ImportedExerciseMap $map): array
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
