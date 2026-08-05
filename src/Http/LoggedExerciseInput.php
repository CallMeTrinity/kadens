<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un exercice réalisé, tel que le téléphone l'envoie (KL-16).
 *
 * Les deux références sont **optionnelles et faibles**, exactement comme les
 * colonnes qu'elles alimentent (`SET NULL` des deux côtés, KL-02) :
 *
 * - `exerciseId` rattache le réalisé à la bibliothèque, ce qui le fait entrer
 *   dans l'historique et les records (`PerformanceHistory`, KL-04). Absent, la
 *   séance reste lisible, elle ne nourrit simplement aucune progression.
 * - `sourcePrescribedId` rattache le réalisé à la **ligne du programme** dont il
 *   découle : c'est lui, et lui seul, qui apparie prévu et fait dans
 *   `LogComparator` (KL-05). Absent = exercice hors programme, cas normal d'une
 *   séance libre ou d'un exercice ajouté à la volée.
 *
 * `name` est le snapshot. Le serveur le remplit depuis l'exercice référencé
 * quand le client ne l'envoie pas — c'est une case explicite du ticket — mais il
 * ne peut pas l'inventer sans référence : un exercice sans nom ni `exerciseId`
 * ni `sourcePrescribedId` est refusé.
 */
final class LoggedExerciseInput
{
    /** Une séance de plus de 100 exercices n'existe pas ; une boucle de synchro emballée, si. */
    public const int MAX_SETS = 100;

    /**
     * @param list<LoggedSetInput> $sets
     */
    public function __construct(
        #[Assert\Positive(message: 'L\'identifiant d\'exercice doit être un entier positif.')]
        public readonly ?int $exerciseId = null,
        #[Assert\Length(max: 255, maxMessage: 'Le nom d\'exercice ne peut pas dépasser {{ limit }} caractères.')]
        public readonly ?string $name = null,
        #[Assert\Positive(message: 'L\'identifiant de la ligne du programme doit être un entier positif.')]
        public readonly ?int $sourcePrescribedId = null,
        public readonly bool $skipped = false,
        public readonly ?string $notes = null,
        #[Assert\Count(max: self::MAX_SETS, maxMessage: 'Un exercice ne peut pas porter plus de {{ limit }} séries.')]
        #[Assert\Valid]
        public readonly array $sets = [],
    ) {
    }
}
