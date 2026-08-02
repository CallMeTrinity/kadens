<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\Workout;

/**
 * Une séance datée telle que l'API la rend : son prescrit à plat et son réalisé,
 * dans **un seul document**.
 *
 * C'est la forme de référence, et elle n'a qu'une définition : `GET /api/bootstrap`
 * (KL-14) la répète pour chaque séance de sa fenêtre, `GET /api/schedule/{uuid}`
 * (KL-15) la rend seule, et `PUT /api/schedule/{uuid}` (KL-16) la reçoit. Le
 * client mobile n'écrit donc **qu'un** désérialiseur — c'est une exigence
 * explicite de KL-15, et la seule façon de la tenir est de n'avoir qu'un endroit
 * qui produit la structure.
 *
 * Trois partis pris :
 *
 * - **Le prescrit vient de `PlanFlattener`, jamais d'une relecture des entités.**
 *   Règle verrouillée du projet (`CLAUDE.md` §3) : l'API ne fait pas exception,
 *   et `setLines` — une entrée par série, dérivée du mode détaillé ou synthétisée
 *   depuis le scalaire — est exactement ce dont le téléphone a besoin pour
 *   pré-remplir une séance.
 * - **Les valeurs sont brutes** (kg, mètres, secondes), le formatage appartient
 *   au client. Une exception assumée : `summary`, la ligne lisible d'un exercice.
 *   Le cardio est hors périmètre de la saisie mobile (§0.4) — il ne s'affiche
 *   qu'en lecture, et réécrire en TypeScript les six branches de
 *   `PlanFlattener::summarize()` pour une chaîne qu'on ne fait que peindre serait
 *   une duplication sans contrepartie.
 * - **Le bloc-notes privé n'entre jamais ici.** `Workout.notes` est le fourre-tout
 *   du propriétaire seul (`CLAUDE.md` §3) ; l'API est une vue de consultation de
 *   plus, elle tombe sous la même garde que l'export Excel, le flux ICS et la page
 *   publique. Ce qui sort, ce sont les notes *adressées à un lecteur* : la
 *   consigne d'un exercice prescrit, la note d'écart de la séance datée.
 *
 * @phpstan-type ApiSetLine array{index: int, type: string, reps: int|null, weightKg: float|null, durationSeconds: int|null}
 * @phpstan-type ApiPrescribed array{prescribedId: int|null, exerciseId: int|null, name: string|null, type: string|null, summary: string, groupLabel: string|null, restSeconds: int|null, rpe: int|null, notes: string|null, sets: list<ApiSetLine>|null}
 * @phpstan-type ApiBlock array{id: int|null, label: string|null, role: string|null, rounds: int|null, exercises: list<ApiPrescribed>}
 * @phpstan-type ApiLoggedSet array{uuid: string, position: int|null, type: string, reps: int|null, weightKg: float|null, durationSeconds: int|null, rpe: int|null, completedAt: string|null}
 * @phpstan-type ApiLoggedExercise array{exerciseId: int|null, name: string|null, sourcePrescribedId: int|null, position: int|null, skipped: bool, notes: string|null, sets: list<ApiLoggedSet>}
 * @phpstan-type ApiScheduledWorkout array{uuid: string, date: string|null, status: string|null, title: string, freeform: bool, startedAt: string|null, endedAt: string|null, completionNotes: string|null, plan: array{id: int|null, title: string|null}|null, blocks: list<ApiBlock>, log: list<ApiLoggedExercise>|null}
 */
final class ScheduledWorkoutPayload
{
    public function __construct(
        private readonly PlanFlattener $flattener,
    ) {
    }

    /**
     * @return ApiScheduledWorkout
     */
    public function build(ScheduledWorkout $scheduled): array
    {
        $workout = $scheduled->getWorkout();
        $plan = $scheduled->getSourcePlanTemplate();

        return [
            // L'uuid et lui seul : le téléphone ne connaît pas les identifiants
            // internes des séances qu'il a créées hors réseau, et c'est ce qui
            // rend `PUT /api/schedule/{uuid}` idempotent.
            'uuid' => (string) $scheduled->getUuid(),
            'date' => $scheduled->getScheduledDate()?->format('Y-m-d'),
            'status' => $scheduled->getStatus()?->value,
            // Titre vivant → snapshot → « Séance libre » : jamais `workout.title`,
            // qui peut être null depuis que la FK est en SET NULL.
            'title' => $scheduled->getDisplayTitle(),
            // Une séance sans source est la seule que le mobile puisse supprimer
            // (KL-16) : le retrait d'une séance issue d'un plan se fait sur le web.
            'freeform' => null === $workout,
            'startedAt' => $scheduled->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'endedAt' => $scheduled->getEndedAt()?->format(\DateTimeInterface::ATOM),
            'completionNotes' => $scheduled->getCompletionNotes(),
            'plan' => null === $plan ? null : ['id' => $plan->getId(), 'title' => $plan->getTitle()],
            // Une séance libre n'a pas de programme, pas un programme vide à
            // deviner : la liste est simplement sans bloc.
            'blocks' => null === $workout ? [] : $this->blocks($workout),
            'log' => $this->log($scheduled),
        ];
    }

    /**
     * @return list<ApiBlock>
     */
    private function blocks(Workout $workout): array
    {
        $blocks = [];

        foreach ($this->flattener->flattenWorkout($workout)['blocks'] as $flatBlock) {
            $block = $flatBlock['block'];

            $exercises = [];
            // La liste **plate** et non les `segments` : un superset y ferait
            // figurer deux fois le même exercice. Le rang dans le groupe voyage
            // sur chaque ligne (`groupLabel`, « A1 »/« A2 »), c'est tout ce qu'il
            // faut pour le dessiner — le mobile ne recompose pas (§0.3 point 3).
            foreach ($flatBlock['exercises'] as $flatPrescribed) {
                $exercises[] = $this->prescribed($flatPrescribed);
            }

            $blocks[] = [
                'id' => $block->getId(),
                'label' => $block->getLabel(),
                'role' => $block->getRole()?->value,
                'rounds' => $block->getRounds(),
                'exercises' => $exercises,
            ];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $flat une entrée `FlatPrescribed` de PlanFlattener
     *
     * @return ApiPrescribed
     */
    private function prescribed(array $flat): array
    {
        /** @var PrescribedExercise $prescribed */
        $prescribed = $flat['prescribed'];
        /** @var Exercise|null $exercise */
        $exercise = $flat['exercise'];

        $sets = null;
        if (null !== $flat['setLines']) {
            $sets = [];
            foreach ($flat['setLines'] as $line) {
                $sets[] = [
                    'index' => $line['index'],
                    'type' => $line['type']->value,
                    'reps' => $line['reps'],
                    'weightKg' => $line['weightKg'],
                    'durationSeconds' => $line['durationSeconds'],
                ];
            }
        }

        return [
            // L'identifiant de la LIGNE du programme : c'est lui que le réalisé
            // renvoie en `sourcePrescribedId`, et lui qui apparie prévu et fait.
            'prescribedId' => $prescribed->getId(),
            'exerciseId' => $exercise?->getId(),
            'name' => $exercise?->getName(),
            'type' => $flat['type']?->value,
            'summary' => $flat['summary'],
            'groupLabel' => $flat['groupLabel'],
            'restSeconds' => $flat['rest'],
            'rpe' => $prescribed->getRpe(),
            // La consigne de l'exercice, adressée à celui qui l'exécute. Rien à
            // voir avec le bloc-notes privé de la séance, qui ne sort jamais.
            'notes' => $flat['notes'],
            'sets' => $sets,
        ];
    }

    /**
     * Le réalisé, ou **null** quand il n'y en a pas — comme
     * `LogMetrics::summary()`. Une séance simplement cochée « faite » sur le web
     * n'a pas un réalisé vide, elle n'en a pas.
     *
     * @return list<ApiLoggedExercise>|null
     */
    private function log(ScheduledWorkout $scheduled): ?array
    {
        if (!$scheduled->hasLog()) {
            return null;
        }

        $exercises = [];
        foreach ($scheduled->getLoggedExercises() as $logged) {
            $exercises[] = [
                'exerciseId' => $logged->getExercise()?->getId(),
                // Le snapshot, pas le nom vivant : c'est ce qui reste lisible
                // quand l'exercice a quitté la bibliothèque.
                'name' => $logged->getExerciseName(),
                'sourcePrescribedId' => $logged->getSourcePrescribedExercise()?->getId(),
                'position' => $logged->getPosition(),
                'skipped' => $logged->isSkipped(),
                'notes' => $logged->getNotes(),
                'sets' => $this->loggedSets($logged),
            ];
        }

        return $exercises;
    }

    /**
     * @return list<ApiLoggedSet>
     */
    private function loggedSets(LoggedExercise $logged): array
    {
        $sets = [];

        foreach ($logged->getLoggedSets() as $set) {
            $sets[] = [
                // L'uuid de la série est posé par le client : c'est la clé sur
                // laquelle une écriture rejouée retombe (KL-16).
                'uuid' => (string) $set->getUuid(),
                'position' => $set->getPosition(),
                'type' => $set->getSetType()->value,
                'reps' => $set->getReps(),
                'weightKg' => $set->getWeightKg(),
                'durationSeconds' => $set->getDurationSeconds(),
                'rpe' => $set->getRpe(),
                'completedAt' => $set->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $sets;
    }
}
