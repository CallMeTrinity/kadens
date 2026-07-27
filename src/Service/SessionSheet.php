<?php

namespace App\Service;

use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Enum\PrescriptionType;
use App\Repository\LoggedSetRepository;

/**
 * Feuille de séance : la prescription et le réalisé fusionnés en UNE structure,
 * consommée par la page d'exécution.
 *
 * Pendant de PlanFlattener pour la boucle prévu vs réalisé — et il le consomme
 * plutôt que de le doubler : le prévu reste mis à plat au même endroit qu'ailleurs
 * dans l'app, on ne fait qu'y accrocher le réalisé. Aucun contrôleur ne doit
 * refaire cette fusion.
 *
 * Définition d'une **ligne validable**, seule autorité du projet :
 * - types de force (SETS_REPS / SETS_TIME) -> une ligne par série, telles que
 *   PlanFlattener::setLines les déroule (donc identiques en mode simple et
 *   détaillé) ;
 * - tous les autres types (course, AMRAP, for time, durée…) -> **une seule**
 *   ligne, index 1, qui veut dire « l'exercice est fait ». Sans ça une séance de
 *   course serait invalidable, alors que la page doit servir toutes les activités.
 *
 * Les séries faites EN PLUS du prévu apparaissent après les lignes prescrites
 * (`extra` = true) : le modèle les accepte sans rien changer, puisque le pointage
 * se fait sur un index et non sur une ligne prescrite existante.
 *
 * @phpstan-type SheetLine array{index: int, extra: bool, type: \App\Enum\SetType|null, typeLabel: string|null, effort: string, plannedReps: int|null, plannedWeightKg: float|null, plannedDurationSeconds: int|null, log: LoggedSet|null, done: bool}
 * @phpstan-type SheetExercise array{prescribed: PrescribedExercise, exercise: \App\Entity\Exercise|null, type: PrescriptionType|null, groupLabel: string|null, values: string, rest: int|null, notes: string|null, perSet: bool, lines: list<SheetLine>, doneCount: int, lineCount: int, complete: bool}
 * @phpstan-type SheetSegment array{label: string|null, kind: string, exercises: list<SheetExercise>}
 * @phpstan-type SheetBlock array{block: \App\Entity\Block, segments: list<SheetSegment>, doneCount: int, lineCount: int}
 * @phpstan-type SheetProgress array{done: int, total: int, remaining: int, complete: bool, percent: int}
 * @phpstan-type Sheet array{scheduled: ScheduledWorkout, workout: \App\Entity\Workout, blocks: list<SheetBlock>, progress: SheetProgress}
 */
final class SessionSheet
{
    public function __construct(
        private readonly PlanFlattener $flattener,
        private readonly LoggedSetRepository $loggedSets,
    ) {
    }

    /**
     * @return Sheet
     */
    public function build(ScheduledWorkout $scheduled): array
    {
        $workout = $scheduled->getWorkout();
        $flat = $this->flattener->flattenWorkout($workout);
        $logs = $this->loggedSets->indexedFor($scheduled);

        $blocks = [];
        $done = 0;
        $total = 0;

        foreach ($flat['blocks'] as $flatBlock) {
            $segments = [];
            $blockDone = 0;
            $blockTotal = 0;

            foreach ($flatBlock['segments'] as $segment) {
                $exercises = [];
                foreach ($segment['exercises'] as $flatExercise) {
                    $entry = $this->buildExercise($flatExercise, $logs);
                    $exercises[] = $entry;
                    $blockDone += $entry['doneCount'];
                    $blockTotal += $entry['lineCount'];
                }

                $segments[] = [
                    'label' => $segment['label'],
                    'kind' => $segment['kind'],
                    'exercises' => $exercises,
                ];
            }

            $blocks[] = [
                'block' => $flatBlock['block'],
                'segments' => $segments,
                'doneCount' => $blockDone,
                'lineCount' => $blockTotal,
            ];

            $done += $blockDone;
            $total += $blockTotal;
        }

        return [
            'scheduled' => $scheduled,
            'workout' => $workout,
            'blocks' => $blocks,
            'progress' => $this->progressOf($done, $total),
        ];
    }

    /**
     * Progression seule, sans construire toute la feuille. Utilisée par les vues
     * qui ne montrent qu'une jauge (page de consultation datée, pastille).
     *
     * @return SheetProgress
     */
    public function progress(ScheduledWorkout $scheduled): array
    {
        $sheet = $this->build($scheduled);

        return $sheet['progress'];
    }

    /**
     * Lignes prescrites d'un exercice, sans le réalisé : ce que « tout valider »
     * doit remplir. Même définition que `build`, d'où l'extraction — les deux ne
     * peuvent pas diverger.
     *
     * @param array{prescribed: PrescribedExercise, type: PrescriptionType|null, values: string, setLines: list<array<string, mixed>>|null} $flatExercise
     *
     * @return list<array{index: int, type: \App\Enum\SetType|null, typeLabel: string|null, effort: string, plannedReps: int|null, plannedWeightKg: float|null, plannedDurationSeconds: int|null}>
     */
    public function plannedLines(array $flatExercise): array
    {
        $setLines = $flatExercise['setLines'] ?? null;

        // Types sans séries : une ligne unique qui vaut « exercice fait ». Son
        // libellé est le résumé de l'exercice (« 5 km @ 5:00 · Z2 »), puisqu'il
        // n'y a pas d'effort par série à afficher.
        if (null === $setLines) {
            return [[
                'index' => 1,
                'type' => null,
                'typeLabel' => null,
                'effort' => $flatExercise['values'],
                'plannedReps' => null,
                'plannedWeightKg' => null,
                'plannedDurationSeconds' => null,
            ]];
        }

        $lines = [];
        foreach ($setLines as $line) {
            $lines[] = [
                'index' => $line['index'],
                'type' => $line['type'],
                'typeLabel' => $line['typeLabel'],
                'effort' => $line['effort'],
                'plannedReps' => $line['reps'],
                'plannedWeightKg' => $line['weightKg'],
                'plannedDurationSeconds' => $line['durationSeconds'],
            ];
        }

        return $lines;
    }

    /**
     * Un exercice de la feuille : ses lignes prescrites décorées du réalisé, plus
     * les séries faites en plus.
     *
     * @param array<string, mixed>                $flatExercise
     * @param array<int, array<int, LoggedSet>>   $logs
     *
     * @return SheetExercise
     */
    private function buildExercise(array $flatExercise, array $logs): array
    {
        /** @var PrescribedExercise $prescribed */
        $prescribed = $flatExercise['prescribed'];
        $prescribedId = $prescribed->getId();
        $forExercise = $logs[$prescribedId] ?? [];

        $lines = [];
        $doneCount = 0;
        $maxPlanned = 0;

        foreach ($this->plannedLines($flatExercise) as $planned) {
            $log = $forExercise[$planned['index']] ?? null;
            $maxPlanned = max($maxPlanned, $planned['index']);
            $doneCount += null !== $log ? 1 : 0;

            $lines[] = $planned + ['extra' => false, 'log' => $log, 'done' => null !== $log];
        }

        // Séries faites au-delà du prévu : elles n'ont pas de ligne prescrite en
        // face, donc pas d'effort attendu à afficher. Elles comptent comme faites
        // mais **pas** dans le total prescrit (voir lineCount) : la progression ne
        // doit pas dépasser 100 % parce qu'on a ajouté une série.
        foreach ($forExercise as $index => $log) {
            if ($index > $maxPlanned) {
                $lines[] = [
                    'index' => $index,
                    'extra' => true,
                    'type' => null,
                    'typeLabel' => null,
                    'effort' => 'en plus',
                    'plannedReps' => null,
                    'plannedWeightKg' => null,
                    'plannedDurationSeconds' => null,
                    'log' => $log,
                    'done' => true,
                ];
            }
        }

        return [
            'prescribed' => $prescribed,
            'exercise' => $flatExercise['exercise'],
            'type' => $flatExercise['type'],
            'groupLabel' => $flatExercise['groupLabel'],
            'values' => $flatExercise['values'],
            'rest' => $flatExercise['rest'],
            'notes' => $flatExercise['notes'],
            // Faux pour les types sans séries : la vue affiche alors une simple
            // bascule « fait », pas un tableau de séries.
            'perSet' => null !== $flatExercise['setLines'],
            'lines' => $lines,
            'doneCount' => $doneCount,
            'lineCount' => $maxPlanned,
            'complete' => $doneCount >= $maxPlanned,
        ];
    }

    /**
     * @return SheetProgress
     */
    private function progressOf(int $done, int $total): array
    {
        return [
            'done' => $done,
            'total' => $total,
            'remaining' => max(0, $total - $done),
            'complete' => $total > 0 && $done >= $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }
}
