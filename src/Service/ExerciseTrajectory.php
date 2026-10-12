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
 * séances). C'est la raison de sa séparation : `PerformanceHistory` sert aussi
 * le téléphone.
 *
 * Les courbes, elles, ne sont plus ici : elles se lisent sur une fenêtre de
 * temps choisie (plan, 6 mois, 1 an, tout), pas sur les dix dernières séances,
 * et c'est `ExerciseProgress` qui les porte.
 *
 * Deux règles portées par la source, et qu'il ne faut pas contourner ici :
 * l'historique lu est **celui de l'utilisateur demandé** (un exercice de la
 * bibliothèque globale est pratiqué par tout le monde, son historique n'appartient
 * qu'à celui qui l'a fait), et la charge lue est celle des **séries de
 * travail** (l'échauffement n'entre jamais dans un record).
 *
 * @phpstan-import-type LastPerformance from PerformanceHistory
 * @phpstan-import-type BestSet from PerformanceHistory
 *
 * @phpstan-type Trajectory array{sessions: list<LastPerformance>, last: LastPerformance, best: BestSet|null}
 */
final class ExerciseTrajectory
{
    /** Profondeur de l'historique montré. Au-delà, ce n'est plus une fiche. */
    public const SESSIONS = 10;

    public function __construct(
        private readonly PerformanceHistory $history,
    ) {
    }

    /**
     * La trajectoire d'un exercice pour un utilisateur, ou **null** quand il ne
     * l'a jamais fait : un exercice sans historique n'affiche rien du tout, pas
     * un cadre vide ni un graphique à zéro.
     *
     * `sessions` est rendu de la plus récente à la plus ancienne (l'ordre de
     * lecture d'une liste). `sessions[0]` **est** la dernière performance :
     * `PerformanceHistory` le garantit, on ne la relit pas.
     *
     * @return Trajectory|null
     */
    public function for(User $user, Exercise $exercise): ?array
    {
        $sessions = $this->history->recentSessions($user, $exercise, self::SESSIONS);

        if ([] === $sessions) {
            return null;
        }

        return [
            'sessions' => $sessions,
            'last' => $sessions[0],
            // Le record est une seconde lecture, et elle ne vaut d'être faite que
            // parce qu'il déborde la fenêtre des dix séances : un record de
            // l'an dernier reste le record.
            'best' => $this->history->bestSet($user, $exercise),
        ];
    }
}
