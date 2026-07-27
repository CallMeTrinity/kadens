<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;
use App\Enum\IntensityZone;
use App\Enum\PaceUnit;
use App\Enum\PrescriptionType;

/**
 * Source unique de mise à plat d'une séance ET d'un plan complet.
 *
 * Produit une structure « plate » traversable, indépendante du rendu. La vue de
 * consultation Twig ET le futur export Excel (Phase 8) consomment cette même
 * sortie. Ne jamais dupliquer cette logique dans un contrôleur.
 *
 * Les valeurs numériques brutes (kg / mètres / secondes) sont conservées telles
 * quelles pour l'export ; un champ `summary` lisible est ajouté pour l'affichage.
 *
 * @phpstan-type FlatSetGroup array{type: \App\Enum\SetType, typeLabel: string|null, count: int, detail: string, effort: string, firstIndex: int, lastIndex: int, weightKg: float|null}
 * @phpstan-type FlatPrescribed array{prescribed: PrescribedExercise, exercise: \App\Entity\Exercise|null, type: PrescriptionType|null, summary: string, values: string, sets: list<FlatSetGroup>|null, rest: ?int, notes: ?string, topWeightKg: ?float, groupLabel: string|null}
 * @phpstan-type FlatSegment array{label: string|null, kind: 'single'|'superset'|'circuit', exercises: list<FlatPrescribed>}
 * @phpstan-type FlatBlock array{block: Block, exercises: list<FlatPrescribed>, segments: list<FlatSegment>}
 * @phpstan-type FlatWorkout array{workout: Workout, blocks: list<FlatBlock>, activities: list<\App\Enum\ActivityType>, exerciseCount: int}
 * @phpstan-type FlatItem array{item: PlanItem, workout: FlatWorkout}
 * @phpstan-type FlatDay array{dayOfWeek: int, items: list<FlatItem>}
 * @phpstan-type FlatWeek array{weekNumber: int, days: list<FlatDay>}
 * @phpstan-type FlatPlan array{template: PlanTemplate, weeks: list<FlatWeek>}
 */
final class PlanFlattener
{
    public function __construct(
        private readonly UnitFormatter $units,
        private readonly WorkoutMetrics $metrics,
        private readonly SupersetGrouper $supersets,
    ) {
    }

    /**
     * Mise à plat d'un plan complet : une grille semaines × jours (1..7, ISO :
     * 1=lundi..7=dimanche), chaque case portant la liste des séances placées,
     * elles-mêmes aplaties. La grille est dense (toutes les cases existent,
     * même vides) pour que le rendu et l'export n'aient aucun trou à gérer.
     *
     * @return FlatPlan
     */
    public function flattenPlanTemplate(PlanTemplate $template): array
    {
        // Indexation des items par semaine/jour pour un accès direct par case.
        $byCell = [];
        foreach ($template->getPlanItems() as $item) {
            $byCell[$item->getWeekNumber()][$item->getDayOfWeek()][] = $item;
        }

        $weeks = [];
        for ($week = 1; $week <= (int) $template->getDurationWeeks(); ++$week) {
            $days = [];
            for ($day = 1; $day <= 7; ++$day) {
                $items = [];
                foreach ($byCell[$week][$day] ?? [] as $item) {
                    $items[] = [
                        'item' => $item,
                        'workout' => $this->flattenWorkout($item->getWorkout()),
                    ];
                }

                $days[] = [
                    'dayOfWeek' => $day,
                    'items' => $items,
                ];
            }

            $weeks[] = [
                'weekNumber' => $week,
                'days' => $days,
            ];
        }

        return [
            'template' => $template,
            'weeks' => $weeks,
        ];
    }

    /**
     * @return FlatWorkout
     */
    public function flattenWorkout(Workout $workout): array
    {
        $blocks = [];
        foreach ($workout->getBlocks() as $block) {
            $blocks[] = $this->flattenBlock($block);
        }

        return [
            'workout' => $workout,
            'blocks' => $blocks,
            // Repères de lecture (badges de case, cartes de palette) : dérivés du
            // contenu, pas stockés. Voir WorkoutMetrics.
            'activities' => $this->metrics->distinctActivities($workout),
            'exerciseCount' => $this->metrics->exerciseCount($workout),
        ];
    }

    /**
     * Un bloc est livré sous deux formes complémentaires, jamais divergentes :
     * `exercises` (liste plate, ordre de lecture) pour tout ce qui n'a que faire
     * des liaisons — export, ICS, aperçu — et `segments` (exercices isolés et
     * groupes de superset) pour les vues qui doivent montrer l'enchaînement.
     *
     * @return FlatBlock
     */
    private function flattenBlock(Block $block): array
    {
        $segments = [];
        $exercises = [];
        foreach ($this->supersets->segments($block) as $segment) {
            $flatExercises = [];
            foreach ($segment['exercises'] as $index => $prescribed) {
                // Rang lisible dans le groupe : « A1 », « A2 »… La lettre vient du
                // segment, le numéro du rang dans le segment.
                $label = null !== $segment['label'] ? $segment['label'].($index + 1) : null;
                $flat = $this->flattenPrescribed($prescribed, $label);
                $flatExercises[] = $flat;
                $exercises[] = $flat;
            }

            $segments[] = [
                'label' => $segment['label'],
                'kind' => $segment['kind'],
                'exercises' => $flatExercises,
            ];
        }

        return [
            'block' => $block,
            'exercises' => $exercises,
            'segments' => $segments,
        ];
    }

    /**
     * @return FlatPrescribed
     */
    private function flattenPrescribed(PrescribedExercise $prescribed, ?string $groupLabel = null): array
    {
        return [
            'prescribed' => $prescribed,
            'exercise' => $prescribed->getExercise(),
            'type' => $prescribed->getPrescriptionType(),
            // `summary` est auto-suffisant (RPE compris) : c'est la chaîne à
            // afficher quand elle est seule — export Excel, aperçu au survol,
            // pastille de calendrier. `values` est la même sans le RPE, pour les
            // vues qui lui donnent sa propre colonne (page de consultation).
            'summary' => $this->summarize($prescribed),
            'values' => $this->summarizeValues($prescribed),
            // Groupes de séries détaillées (mode force détaillé), null en mode simple.
            'sets' => $prescribed->hasDetailedSets() ? $this->detailedSetGroups($prescribed) : null,
            'rest' => $prescribed->getRestSeconds(),
            'notes' => $prescribed->getNotes(),
            // Référence du « % du max » affiché par le tableau de séries.
            'topWeightKg' => $prescribed->getTopWeightKg(),
            // Rang dans le superset (« A1 », « A2 »…), null hors liaison.
            'groupLabel' => $groupLabel,
        ];
    }

    /**
     * Résumé lisible d'un exercice prescrit selon son type d'effort.
     */
    private function summarize(PrescribedExercise $pe): string
    {
        $summary = $this->summarizeValues($pe);

        // RPE : transverse à tous les types, ajouté en suffixe s'il est renseigné.
        if (null !== $pe->getRpe()) {
            $summary = trim($summary.sprintf(' · RPE %d', $pe->getRpe()));
        }

        return $summary;
    }

    /**
     * Le corps du résumé, sans le suffixe RPE : ce que l'exercice demande de
     * faire, indépendamment de l'intensité ressentie.
     */
    private function summarizeValues(PrescribedExercise $pe): string
    {
        return match ($pe->getPrescriptionType()) {
            PrescriptionType::SETS_REPS => $this->summarizeSetsReps($pe),
            PrescriptionType::SETS_TIME => $this->summarizeSetsTime($pe),
            PrescriptionType::AMRAP => $this->summarizeAmrap($pe),
            PrescriptionType::FOR_TIME => $this->summarizeForTime($pe),
            PrescriptionType::DISTANCE_PACE => $this->summarizeDistancePace($pe),
            PrescriptionType::DURATION => $this->summarizeDuration($pe),
            null => '',
        };
    }

    /**
     * Libellé lisible d'une zone d'intensité stockée (« z4 » -> « Z4 Seuil »).
     * Toute valeur hors enum (ancienne saisie libre) est renvoyée telle quelle.
     */
    private function intensityLabel(?string $zone): ?string
    {
        if (null === $zone || '' === $zone) {
            return null;
        }

        return IntensityZone::tryFrom($zone)?->shortLabel() ?? $zone;
    }

    private function summarizeSetsReps(PrescribedExercise $pe): string
    {
        if ($pe->hasDetailedSets()) {
            return $this->summarizeDetailedSets($pe);
        }

        $summary = trim(sprintf('%s × %s', $pe->getSets() ?? '?', $pe->getReps() ?? '?'));

        if (null !== $pe->getWeightKg()) {
            $summary .= ' @ '.$this->units->weight($pe->getWeightKg());
        }

        return $summary;
    }

    private function summarizeSetsTime(PrescribedExercise $pe): string
    {
        if ($pe->hasDetailedSets()) {
            return $this->summarizeDetailedSets($pe);
        }

        $summary = sprintf('%s × %s', $pe->getSets() ?? '?', $this->units->duration($pe->getDurationSeconds()));

        if (null !== $pe->getWeightKg()) {
            $summary .= ' @ '.$this->units->weight($pe->getWeightKg());
        }

        return $summary;
    }

    /**
     * Résumé compact d'une prescription en « séries détaillées » : chaque groupe
     * de séries consécutives identiques devient un jeton « [N×] [Type] détail »,
     * joints par « · ». Ex. « Échauf 10 reps @ 40 kg · 3× 8 reps @ 100 kg · Drop 6 reps @ 80 kg ».
     */
    private function summarizeDetailedSets(PrescribedExercise $pe): string
    {
        $parts = [];
        foreach ($this->detailedSetGroups($pe) as $group) {
            $token = '';
            if ($group['count'] > 1) {
                $token .= $group['count'].'× ';
            }
            if (null !== $group['typeLabel']) {
                $token .= $group['typeLabel'].' ';
            }
            $parts[] = trim($token.$group['detail']);
        }

        return implode(' · ', $parts);
    }

    /**
     * Regroupe les séries détaillées consécutives identiques (même type et mêmes
     * valeurs) pour une lecture dense. Source unique consommée par le résumé
     * compact ET le rendu détaillé en lecture.
     *
     * Chaque groupe porte aussi son rang réel dans la série (`firstIndex` /
     * `lastIndex`, base 1) pour que le tableau de lecture puisse afficher
     * « 03 — 06 », et sa charge brute (`weightKg`) pour dériver le pourcentage
     * de la charge la plus lourde de l'exercice. Le regroupement condense
     * l'affichage, il ne doit pas faire perdre la numérotation.
     *
     * @return list<FlatSetGroup>
     */
    private function detailedSetGroups(PrescribedExercise $pe): array
    {
        $groups = [];
        $position = 0;

        foreach ($pe->getDetailedSets() as $set) {
            ++$position;
            $detail = $this->detailedSetDetail($pe, $set);
            $key = $set->getSetType()->value.'|'.$detail;

            $lastIndex = array_key_last($groups);
            if (null !== $lastIndex && $groups[$lastIndex]['key'] === $key) {
                ++$groups[$lastIndex]['count'];
                $groups[$lastIndex]['lastIndex'] = $position;
                continue;
            }

            $short = $set->getSetType()->shortLabel();
            $groups[] = [
                'key' => $key,
                'type' => $set->getSetType(),
                'typeLabel' => '' !== $short ? $short : null,
                'count' => 1,
                'detail' => $detail,
                'effort' => $this->detailedSetEffort($pe, $set),
                'firstIndex' => $position,
                'lastIndex' => $position,
                'weightKg' => $set->getWeightKg(),
            ];
        }

        // On retire la clé technique de regroupement.
        return array_map(
            static fn (array $g): array => [
                'type' => $g['type'],
                'typeLabel' => $g['typeLabel'],
                'count' => $g['count'],
                'detail' => $g['detail'],
                'effort' => $g['effort'],
                'firstIndex' => $g['firstIndex'],
                'lastIndex' => $g['lastIndex'],
                'weightKg' => $g['weightKg'],
            ],
            $groups,
        );
    }

    /**
     * Détail lisible d'une série selon le type de force parent (reps pour
     * SETS_REPS, durée pour SETS_TIME) + charge éventuelle.
     */
    private function detailedSetDetail(PrescribedExercise $pe, PrescribedSet $set): string
    {
        $detail = $this->detailedSetEffort($pe, $set);

        if (null !== $set->getWeightKg()) {
            $detail .= ' @ '.$this->units->weight($set->getWeightKg());
        }

        return $detail;
    }

    /**
     * L'effort d'une série sans sa charge — reps pour SETS_REPS, durée pour
     * SETS_TIME. Le tableau de lecture donne à la charge sa propre colonne :
     * il lui faut la valeur nue, pas la chaîne assemblée de `detail`.
     */
    private function detailedSetEffort(PrescribedExercise $pe, PrescribedSet $set): string
    {
        if (PrescriptionType::SETS_TIME === $pe->getPrescriptionType()) {
            return $this->units->duration($set->getDurationSeconds());
        }

        return sprintf('%s reps', $set->getReps() ?? '?');
    }

    private function summarizeAmrap(PrescribedExercise $pe): string
    {
        $summary = 'AMRAP '.$this->units->duration($pe->getDurationSeconds());

        if (null !== $pe->getTargetReps()) {
            $summary .= sprintf(' · cible %d reps', $pe->getTargetReps());
        }

        return $summary;
    }

    private function summarizeForTime(PrescribedExercise $pe): string
    {
        $summary = sprintf('%s reps for time', $pe->getTargetReps() ?? '?');

        if (null !== $pe->getCapSeconds()) {
            $summary .= ' · cap '.$this->units->duration($pe->getCapSeconds());
        }

        return $summary;
    }

    private function summarizeDistancePace(PrescribedExercise $pe): string
    {
        $summary = $this->units->distance($pe->getDistanceMeters());

        // Intervalles : « 8 × 400 m » quand un nombre de répétitions est posé.
        if (null !== $pe->getSets()) {
            $summary = sprintf('%d × %s', $pe->getSets(), $summary);
        }

        if (null !== $pe->getPaceSecondsPerKm()) {
            // Allure affichée dans l'unité naturelle de l'activité de l'exercice
            // (course min/km, vélo km/h, natation min/100m).
            $unit = PaceUnit::forActivity($pe->getExercise()?->getActivity());
            $summary .= ' @ '.$this->units->pace($pe->getPaceSecondsPerKm(), $unit);
        }

        if (null !== ($zone = $this->intensityLabel($pe->getIntensityZone()))) {
            $summary .= ' · '.$zone;
        }

        if (null !== $pe->getElevationGainMeters()) {
            $summary .= sprintf(' · D+ %d m', $pe->getElevationGainMeters());
        }

        return $summary;
    }

    private function summarizeDuration(PrescribedExercise $pe): string
    {
        $summary = $this->units->duration($pe->getDurationSeconds());

        if (null !== $pe->getPaceSecondsPerKm()) {
            $unit = PaceUnit::forActivity($pe->getExercise()?->getActivity());
            $summary .= ' @ '.$this->units->pace($pe->getPaceSecondsPerKm(), $unit);
        }

        if (null !== ($zone = $this->intensityLabel($pe->getIntensityZone()))) {
            $summary .= ' · '.$zone;
        }

        return $summary;
    }
}
