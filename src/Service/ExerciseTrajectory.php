<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;

/**
 * « Est-ce que je progresse sur cet exercice ? » — la question à laquelle
 * `/exercise/{id}` est le seul endroit à pouvoir répondre sans passer par un
 * plan, toutes séances et tous plans confondus (KL-50).
 *
 * Ce service ne lit rien lui-même : il **met en forme** ce que
 * `PerformanceHistory` rend déjà (record, dernière performance, dix dernières
 * séances), et n'y ajoute que ce qui appartient à l'écran — l'ordre
 * chronologique de la courbe et la hauteur des barres. C'est la raison de sa
 * séparation : `PerformanceHistory` sert aussi le téléphone, où un pourcentage
 * de hauteur n'a rien à faire.
 *
 * Deux règles portées par la source, et qu'il ne faut pas contourner ici :
 * l'historique lu est **celui de l'utilisateur demandé** (un exercice de la
 * bibliothèque globale est pratiqué par tout le monde, son historique n'appartient
 * qu'à celui qui l'a fait), et la charge tracée est celle des **séries de
 * travail** (l'échauffement n'entre ni dans un record ni dans une courbe).
 *
 * @phpstan-import-type LastPerformance from PerformanceHistory
 * @phpstan-import-type BestSet from PerformanceHistory
 *
 * @phpstan-type CurvePoint array{scheduledWorkoutId: int, date: \DateTimeImmutable, weightKg: float, label: string, heightPct: int}
 * @phpstan-type Trajectory array{sessions: list<LastPerformance>, last: LastPerformance, best: BestSet|null, points: list<CurvePoint>, direction: string, firstLabel: string|null, lastLabel: string|null}
 */
final class ExerciseTrajectory
{
    /** Profondeur de l'historique montré. Au-delà, ce n'est plus une fiche. */
    public const SESSIONS = 10;

    public function __construct(
        private readonly PerformanceHistory $history,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * La trajectoire d'un exercice pour un utilisateur, ou **null** quand il ne
     * l'a jamais fait : un exercice sans historique n'affiche rien du tout, pas
     * un cadre vide ni un graphique à zéro.
     *
     * `sessions` est rendu de la plus récente à la plus ancienne (l'ordre de
     * lecture d'une liste), `points` dans l'ordre inverse (celui d'une courbe).
     * `sessions[0]` **est** la dernière performance : `PerformanceHistory` le
     * garantit, on ne la relit pas.
     *
     * @return Trajectory|null
     */
    public function for(User $user, Exercise $exercise): ?array
    {
        $sessions = $this->history->recentSessions($user, $exercise, self::SESSIONS);

        if ([] === $sessions) {
            return null;
        }

        $points = $this->curve(array_reverse($sessions));

        return [
            'sessions' => $sessions,
            'last' => $sessions[0],
            // Le record est une seconde lecture, et elle ne vaut d'être faite que
            // parce qu'il déborde la fenêtre des dix séances : un record de
            // l'an dernier reste le record.
            'best' => $this->history->bestSet($user, $exercise),
            'points' => $points,
            'direction' => $this->direction($points),
            'firstLabel' => $points[0]['label'] ?? null,
            'lastLabel' => $points !== [] ? $points[\count($points) - 1]['label'] : null,
        ];
    }

    /**
     * La courbe de charge : un point par séance **chargée**, à la meilleure série
     * de travail du jour. Les séances sans kilos (poids du corps, gainage) n'y
     * entrent pas — il n'y a pas de charge à tracer.
     *
     * Moins de deux points, pas de courbe : un point unique n'est pas une
     * trajectoire, et le dessiner ne dirait que « il y a eu une séance », ce que
     * la liste dit déjà mieux.
     *
     * @param list<LastPerformance> $chronological de la plus ancienne à la plus récente
     *
     * @return list<CurvePoint>
     */
    private function curve(array $chronological): array
    {
        $charged = array_values(array_filter(
            $chronological,
            static fn (array $session): bool => null !== $session['topWeightKg'],
        ));

        if (\count($charged) < 2) {
            return [];
        }

        $weights = array_map(static fn (array $s): float => (float) $s['topWeightKg'], $charged);
        $min = min($weights);
        $span = max($weights) - $min;

        $points = [];
        foreach ($charged as $index => $session) {
            $weight = $weights[$index];
            $points[] = [
                'scheduledWorkoutId' => $session['scheduledWorkoutId'],
                'date' => $session['date'],
                'weightKg' => $weight,
                'label' => $this->units->weight($weight),
                // Échelle entre min et max, plancher 15 % : sur dix séances, l'écart
                // de charge est souvent faible devant la charge elle-même, et une
                // échelle partant de zéro écraserait toute la progression.
                'heightPct' => $span <= 0.0 ? 60 : (int) round(15 + ($weight - $min) / $span * 85),
            ];
        }

        return $points;
    }

    /**
     * Le sens de la courbe : première vs dernière charge tracée. Volontairement
     * binaire — c'est un repère de lecture, pas une statistique.
     *
     * @param list<CurvePoint> $points
     */
    private function direction(array $points): string
    {
        if (\count($points) < 2) {
            return 'flat';
        }

        $first = $points[0]['weightKg'];
        $last = $points[\count($points) - 1]['weightKg'];

        return match (true) {
            $last > $first => 'up',
            $last < $first => 'down',
            default => 'flat',
        };
    }
}
