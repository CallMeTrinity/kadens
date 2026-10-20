<?php

declare(strict_types=1);

namespace App\Service;

/**
 * La forme d'un réglage de machine dans l'API mobile, écrite **une fois** : le
 * bootstrap la descend (`setups`), `PUT /api/exercises/{id}/setup` la rend. Un
 * seul producteur, donc un seul désérialiseur côté client — la règle de
 * `PerformanceHistoryPayload`, appliquée ici.
 *
 * @phpstan-type ApiExerciseSetup array{exerciseId: int, text: string|null, updatedAt: string|null}
 */
final class ExerciseSetupPayload
{
    /**
     * `text` et `updatedAt` sont nuls ensemble, et seulement dans la réponse d'un
     * `PUT` qui vient d'effacer le réglage : le bootstrap, lui, ne descend que
     * les réglages qui existent.
     *
     * @return ApiExerciseSetup
     */
    public static function entry(int $exerciseId, ?string $text, ?\DateTimeImmutable $updatedAt): array
    {
        return [
            'exerciseId' => $exerciseId,
            'text' => $text,
            'updatedAt' => $updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
