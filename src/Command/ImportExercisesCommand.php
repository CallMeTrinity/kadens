<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\TargetArea;
use App\Repository\ExerciseRepository;
use App\Repository\UserRepository;
use App\Service\TextNormalizer;
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
 * Synchronise la bibliothèque avec `data/exercises.json`.
 *
 * ## Pourquoi elle apparie sur une clé et pas sur le nom
 *
 * La version précédente cherchait `findOneBy(['name' => $name])` et **sautait**
 * ce qu'elle trouvait : aucune mise à jour, et surtout un appariement qui
 * cassait au premier renommage. Corriger un libellé dans le fichier créait
 * alors un second exercice avec un nouvel `id`, pendant que
 * `LoggedExercise.exercise`, `PrescribedExercise.exercise`,
 * `LoggedExerciseRepository::usageForOwner()` et la base locale du téléphone
 * continuaient de pointer l'ancien. Le renommage coûtait l'historique.
 *
 * `Exercise.refKey` sépare l'identité du libellé. La commande apparie
 * là-dessus, et **converge** : elle réécrit les champs de la ligne trouvée au
 * lieu de la sauter. Renommer en français, ajouter un nom anglais, corriger une
 * zone : tout se resynchronise sans qu'aucun `id` ne bouge.
 *
 * ## L'adoption, et pourquoi elle reste
 *
 * Les 301 lignes déjà en base sont nées sans `refKey`. Un appariement par clé
 * seule les aurait toutes recréées — exactement ce qu'on veut éviter. Avant de
 * créer, la commande cherche donc une ligne **sans clé** dont le nom normalisé
 * correspond, et **l'adopte** : elle lui pose la clé, puis la met à jour.
 *
 * Trois formes candidates : le `name` du fichier, `"{name} ({nameEn})"` —
 * l'ancienne convention, où l'anglais traînait entre parenthèses dans le nom
 * français — et les `formerNames` déclarés sur l'entrée.
 *
 * `formerNames` existe parce que la dérivation ne suffit pas : quand le
 * nettoyage a **inversé** les deux langues (`Front Squat (Squat avant)` devenu
 * `Squat avant` / `Front squat`), ou quand le nom anglais retenu est plus précis
 * que la parenthèse d'origine (`(Seated row)` devenu `Seated cable row`), aucune
 * règle ne retrouve l'ancien libellé. Le déclarer est la seule façon honnête de
 * le dire, et ça vaut mieux qu'une heuristique qui adopterait de travers.
 *
 * L'adoption n'est pas un dispositif de migration jetable : elle reste un filet
 * permanent. Un `ROLE_ADMIN` qui crée « Développé décliné » dans l'app le crée
 * en global sans clé ; le jour où le fichier gagne cette entrée, elle est
 * adoptée au lieu d'être dupliquée.
 *
 * ## Ce qu'elle ne fait pas
 *
 * **Jamais de suppression.** Retirer une entrée du fichier ne retire rien de la
 * base : une ligne peut être référencée par un historique, et la commande n'a
 * pas à en décider. C'est `/exercise` qui supprime, avec son voter.
 *
 * ## Le mode `--owner`
 *
 * `refKey` est unique sur toute la table et ne vaut que pour la globale : deux
 * utilisateurs important le même fichier la violeraient. En mode `--owner`, la
 * commande n'en pose donc aucune et apparie par nom normalisé, scopé sur cet
 * utilisateur.
 */
#[AsCommand(
    name: 'app:import-exercises',
    description: 'Synchronise la bibliothèque avec un fichier JSON (crée, adopte et met à jour ; ne supprime jamais).',
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
                'Email du User propriétaire des exercices (défaut : bibliothèque globale sans owner)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Affiche ce qui serait fait sans rien écrire.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        /** @var string|null $fileArg */
        $fileArg = $input->getArgument('file');
        $file = $fileArg ?? $this->projectDir.'/data/exercises.json';

        $rows = $this->readRows($file, $io);

        if (null === $rows) {
            return Command::FAILURE;
        }

        $owner = null;

        if (\is_string($ownerEmail = $input->getOption('owner')) && '' !== $ownerEmail) {
            $owner = $this->userRepository->findOneBy(['email' => $ownerEmail]);

            if (null === $owner) {
                $io->error(\sprintf('Aucun User trouvé pour l\'email : %s', $ownerEmail));

                return Command::FAILURE;
            }
        }

        [$byRefKey, $byName] = $this->indexExisting($owner);

        $created = 0;
        $adopted = 0;
        $updated = 0;
        $unchanged = 0;
        $errors = 0;

        /** @var array<string, int> $seenKeys clé => ligne, pour refuser un doublon INTRA-fichier */
        $seenKeys = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;

            if (!\is_array($row) || !isset($row['name']) || !\is_string($row['name']) || '' === trim($row['name'])) {
                $io->warning(\sprintf('#%d : "name" manquant ou invalide, ignoré.', $line));
                ++$errors;
                continue;
            }

            $name = trim($row['name']);
            $nameEn = isset($row['nameEn']) && \is_string($row['nameEn']) && '' !== trim($row['nameEn'])
                ? trim($row['nameEn'])
                : null;

            $key = isset($row['key']) && \is_string($row['key']) ? trim($row['key']) : '';

            // La clé est ce qui rend la commande rejouable : sans elle, on
            // retomberait sur l'appariement par nom que ce champ remplace.
            // Exigée en global seulement — une bibliothèque perso n'en porte pas.
            if (null === $owner && '' === $key) {
                $io->warning(\sprintf('#%d "%s" : "key" manquante, ignoré.', $line, $name));
                ++$errors;
                continue;
            }

            if ('' !== $key && isset($seenKeys[$key])) {
                $io->error(\sprintf(
                    '#%d "%s" : la clé « %s » est déjà utilisée ligne #%d. Deux entrées ne peuvent pas partager une clé.',
                    $line,
                    $name,
                    $key,
                    $seenKeys[$key],
                ));

                return Command::FAILURE;
            }

            if ('' !== $key) {
                $seenKeys[$key] = $line;
            }

            $activity = ActivityType::tryFrom((string) ($row['activity'] ?? ''));

            if (null === $activity) {
                $io->warning(\sprintf('#%d "%s" : activité inconnue ("%s"), ignoré.', $line, $name, (string) ($row['activity'] ?? '')));
                ++$errors;
                continue;
            }

            $targetAreas = [];

            foreach ((array) ($row['targetAreas'] ?? []) as $area) {
                $target = TargetArea::tryFrom((string) $area);

                if (null === $target) {
                    $io->warning(\sprintf('#%d "%s" : zone inconnue ("%s"), ignorée.', $line, $name, (string) $area));
                    continue;
                }

                $targetAreas[] = $target;
            }

            $description = isset($row['description']) && \is_string($row['description']) ? $row['description'] : null;
            $mediaUrl = isset($row['mediaUrl']) && \is_string($row['mediaUrl']) ? $row['mediaUrl'] : null;

            // `refKey` est unique sur TOUTE la table et ne vaut que pour la
            // globale : deux utilisateurs important le même fichier la
            // violeraient au deuxième. En mode `--owner`, on lit encore la clé
            // du fichier — elle sert au refus des doublons ci-dessus — mais on
            // n'en pose aucune, et l'appariement se fait par nom, scopé sur
            // l'utilisateur par `indexExisting()`.
            $stampKey = null === $owner ? $key : '';

            $formerNames = $this->strings($row['formerNames'] ?? []);
            $formerKeys = $this->strings($row['formerKeys'] ?? []);

            // 1. La clé, quand la ligne en porte déjà une.
            $exercise = '' !== $stampKey ? ($byRefKey[$stampKey] ?? null) : null;
            $wasAdopted = false;

            // 1 bis. Une clé qu'elle a portée. Une `refKey` n'est PAS censée
            // changer — mais quand ça arrive, l'adoption par nom ne rattrape
            // rien : elle refuse toute ligne déjà cléfée, précisément pour ne
            // pas voler celle d'une autre entrée. Sans ce rattrapage, changer
            // une clé créerait un doublon et détacherait l'historique, en
            // silence. La déclarer est le seul chemin de retour.
            if (null === $exercise && '' !== $stampKey) {
                foreach ($formerKeys as $former) {
                    if (isset($byRefKey[$former])) {
                        $exercise = $byRefKey[$former];
                        $exercise->setRefKey($stampKey);
                        $byRefKey[$stampKey] = $exercise;
                        unset($byRefKey[$former]);
                        $wasAdopted = true;
                        break;
                    }
                }
            }

            // 2. Sinon, l'adoption par nom normalisé.
            if (null === $exercise) {
                $exercise = $this->findAdoptable($byName, $name, $nameEn, $formerNames);

                if (null !== $exercise) {
                    $wasAdopted = true;

                    if ('' !== $stampKey) {
                        $exercise->setRefKey($stampKey);
                        $byRefKey[$stampKey] = $exercise;
                    }
                }
            }

            // 3. Sinon, création.
            if (null === $exercise) {
                $exercise = new Exercise();
                $exercise->setOwner($owner);
                $exercise->setRefKey('' !== $stampKey ? $stampKey : null);
                $this->em->persist($exercise);

                if ('' !== $stampKey) {
                    $byRefKey[$stampKey] = $exercise;
                }

                $this->apply($exercise, $name, $nameEn, $description, $activity, $targetAreas, $mediaUrl);
                ++$created;
                $io->writeln(\sprintf('  <fg=green>+</> %s', $name), OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }

            $changed = $this->apply($exercise, $name, $nameEn, $description, $activity, $targetAreas, $mediaUrl);

            if ($wasAdopted) {
                ++$adopted;
                $io->writeln(\sprintf('  <fg=cyan>~</> %s (adopté)', $name), OutputInterface::VERBOSITY_VERBOSE);
            } elseif ($changed) {
                ++$updated;
                $io->writeln(\sprintf('  <fg=yellow>*</> %s', $name), OutputInterface::VERBOSITY_VERBOSE);
            } else {
                ++$unchanged;
            }
        }

        if ($dryRun) {
            $io->note('Simulation : rien n\'a été écrit. Relance sans --dry-run pour appliquer.');
        } else {
            $this->em->flush();
        }

        $io->success(\sprintf(
            '%d créé(s), %d adopté(s), %d mis à jour, %d inchangé(s), %d en erreur.',
            $created,
            $adopted,
            $updated,
            $unchanged,
            $errors,
        ));

        return $errors > 0 ? Command::INVALID : Command::SUCCESS;
    }

    /**
     * Le contenu du fichier, ou `null` si quoi que ce soit cloche (le message
     * est déjà écrit).
     *
     * @return array<int, mixed>|null
     */
    private function readRows(string $file, SymfonyStyle $io): ?array
    {
        if (!is_file($file)) {
            $io->error(\sprintf('Fichier introuvable : %s', $file));

            return null;
        }

        $raw = file_get_contents($file);

        if (false === $raw) {
            $io->error(\sprintf('Impossible de lire le fichier : %s', $file));

            return null;
        }

        try {
            /** @var mixed $rows */
            $rows = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(\sprintf('JSON invalide : %s', $e->getMessage()));

            return null;
        }

        if (!\is_array($rows) || !array_is_list($rows)) {
            $io->error('Le JSON racine doit être un tableau d\'exercices.');

            return null;
        }

        return $rows;
    }

    /**
     * Les exercices déjà en base, indexés deux fois : par clé stable, et par nom
     * normalisé pour l'adoption.
     *
     * La portée suit le mode : la globale seule (`owner IS NULL`) par défaut,
     * les exercices de l'utilisateur seul en mode `--owner`. Un import global ne
     * doit pas pouvoir adopter l'exercice perso de quelqu'un, ni l'inverse —
     * c'était le défaut de l'ancien `findOneBy` non scopé, qui faisait sauter
     * une entrée globale parce qu'un utilisateur s'était créé le même nom.
     *
     * @return array{0: array<string, Exercise>, 1: array<string, Exercise>}
     */
    private function indexExisting(?User $owner): array
    {
        $existing = $this->exerciseRepository->findBy(['owner' => $owner]);

        $byRefKey = [];
        $byName = [];

        foreach ($existing as $exercise) {
            if (null !== $key = $exercise->getRefKey()) {
                $byRefKey[$key] = $exercise;
            }

            $normalized = TextNormalizer::normalize((string) $exercise->getName());

            // Collision de noms normalisés : on garde le premier. Adopter au
            // hasard serait pire qu'une création, qu'on verra passer dans le
            // résumé.
            $byName[$normalized] ??= $exercise;
        }

        return [$byRefKey, $byName];
    }

    /**
     * Les chaînes non vides d'une valeur du fichier, quoi qu'elle contienne.
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $out = [];

        foreach ((array) $value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * Une ligne existante **sans clé** dont le nom désigne le même mouvement.
     *
     * Trois formes candidates : le nom du fichier, l'ancienne convention où
     * l'anglais traînait entre parenthèses (`Traction en supination (Chin-up)`),
     * et les libellés que l'entrée déclare avoir portés.
     *
     * L'ordre compte : le nom courant d'abord, les anciens ensuite. Une entrée
     * dont l'ancien libellé est devenu le nom courant d'une **autre** entrée ne
     * doit pas la lui voler.
     *
     * Une ligne qui porte déjà une clé n'est jamais adoptée : elle appartient à
     * une autre entrée du fichier, et la lui prendre produirait deux entrées
     * pointant le même exercice.
     *
     * @param array<string, Exercise> $byName
     * @param list<string>            $formerNames
     */
    private function findAdoptable(array $byName, string $name, ?string $nameEn, array $formerNames): ?Exercise
    {
        $candidates = [$name];

        if (null !== $nameEn) {
            $candidates[] = \sprintf('%s (%s)', $name, $nameEn);
        }

        foreach ($formerNames as $former) {
            $candidates[] = $former;
        }

        foreach ($candidates as $candidate) {
            $found = $byName[TextNormalizer::normalize($candidate)] ?? null;

            if (null !== $found && null === $found->getRefKey()) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Écrit les champs pilotés par le fichier et dit si quelque chose a bougé.
     *
     * La comparaison est faite à la main plutôt que laissée à l'UnitOfWork :
     * elle doit rester juste en `--dry-run`, où aucun changeset n'est calculé.
     *
     * @param list<TargetArea> $targetAreas
     */
    private function apply(
        Exercise $exercise,
        string $name,
        ?string $nameEn,
        ?string $description,
        ActivityType $activity,
        array $targetAreas,
        ?string $mediaUrl,
    ): bool {
        $areas = [] === $targetAreas ? null : $targetAreas;

        $changed = $exercise->getName() !== $name
            || $exercise->getNameEn() !== $nameEn
            || $exercise->getDescription() !== $description
            || $exercise->getActivity() !== $activity
            || $exercise->getTargetAreas() !== $areas
            || $exercise->getMediaUrl() !== $mediaUrl;

        $exercise->setName($name);
        $exercise->setNameEn($nameEn);
        $exercise->setDescription($description);
        $exercise->setActivity($activity);
        $exercise->setTargetAreas($areas);
        $exercise->setMediaUrl($mediaUrl);

        return $changed;
    }
}
