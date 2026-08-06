<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ExerciseRepository;
use App\Repository\UserRepository;
use App\Service\FitNotesCsvParser;
use App\Service\TrainingHistoryImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reprise d'un historique d'entraînement exporté en CSV par FitNotes.
 *
 * Le déroulé (rapport, mapping à la main, dry-run, `--force`) et les raisons qui
 * le justifient sont dans `AbstractHistoryImportCommand` : cette classe ne dit
 * que ce qui est propre à FitNotes.
 *
 * Deux différences se voient à l'usage. `--timezone` ne déplace aucune heure ici
 * (l'export n'en porte pas), il ne sert qu'à situer le jour. Et la clé de
 * correspondance est le **nom seul** de l'exercice, sans `|équipement` : le
 * fichier de mapping n'a donc pas la même forme que celui de Blast, d'où deux
 * fichiers distincts dans `data/`.
 */
#[AsCommand(
    name: 'app:import:fitnotes',
    description: 'Importe un historique de séances exporté en CSV par FitNotes, en séances datées « Faite ».',
)]
final class ImportFitNotesWorkoutsCommand extends AbstractHistoryImportCommand
{
    public function __construct(
        EntityManagerInterface $em,
        UserRepository $users,
        ExerciseRepository $exercises,
        TrainingHistoryImporter $importer,
        private readonly FitNotesCsvParser $parser,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        parent::__construct($em, $users, $exercises, $importer, $projectDir);
    }

    protected function parseFile(string $file, string $timezone): array
    {
        return $this->parser->parse($file, $timezone);
    }

    protected function defaultTimezone(): string
    {
        return FitNotesCsvParser::DEFAULT_TIMEZONE;
    }

    protected function defaultGlob(): string
    {
        return 'data/fitnote*.csv';
    }

    protected function defaultMapFile(): string
    {
        return 'data/fitnotes-exercise-map.json';
    }
}
