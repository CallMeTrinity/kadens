<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\ScheduledStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * La séance datée telle que le téléphone l'envoie à `PUT /api/schedule/{uuid}`
 * (KL-16) : **un document complet**, pas une série à la fois.
 *
 * C'est le miroir entrant de `ScheduledWorkoutPayload`, et il est volontairement
 * **plus étroit que lui**. Ce que le téléphone renvoie du document qu'il a
 * descendu et qui ne figure pas ici (`blocks`, `plan`, `freeform`, les rangs) est
 * simplement ignoré : le sérialiseur tolère les attributs en trop, ce qui permet
 * au client de renvoyer tel quel ce que le bootstrap lui a donné, sans en faire
 * pour autant des champs modifiables.
 *
 * **Le partage d'autorité, qui est tout le ticket** : le téléphone fait autorité
 * sur le **réalisé**, le serveur sur la **programmation**. §0.3 point 1 dit « le
 * mobile est la seule source d'écriture du réalisé » — pas du planning. D'où :
 *
 * - `log`, `startedAt`, `endedAt` sont du réalisé : le document les **écrase**.
 * - `date` ne sert qu'à la **création**. Sur une séance connue elle est ignorée :
 *   déplacer une séance est un geste de programmation (`EDIT`, ouvert au coach),
 *   et un téléphone resté trois jours hors réseau ramènerait sinon à son ancienne
 *   date la séance que le coach vient de décaler.
 * - `title` de même : il nomme une séance libre au moment où elle naît. Une
 *   séance issue de la bibliothèque tient son titre de sa source.
 * - `status` ne peut que **clôturer** (`done`). Les autres valeurs sont acceptées
 *   sans effet — un client qui renvoie le document reçu ne doit pas se prendre un
 *   422 pour un `planned` qu'il n'a fait que recopier — mais rien ne
 *   *déclôture* : §2.3 point 5, « pas de reprise après clôture ».
 * - `completionNotes` s'écrit si le document en porte une, et **n'efface jamais**
 *   celle qui existe : c'est la note de clôture de KL-33 côté téléphone, et la
 *   note d'écart du coach côté web. Le silence du premier n'est pas un ordre
 *   d'effacer la seconde.
 */
final class ScheduledWorkoutInput
{
    /** Autant qu'un exercice porte de séries : la même borne pour la même raison. */
    public const int MAX_EXERCISES = 100;

    /**
     * @param list<LoggedExerciseInput> $log
     */
    public function __construct(
        // Facultatif, mais s'il est là il doit dire la même chose que l'URL. Deux
        // identifiants qui se contredisent dans une requête d'upsert, c'est la
        // question « laquelle des deux lignes j'écris » posée au serveur.
        #[Assert\Uuid(message: 'L\'identifiant de la séance doit être un UUID.')]
        public readonly ?string $uuid = null,
        #[Assert\NotNull(message: 'La date de la séance est requise.')]
        #[Assert\Date(message: 'La date doit être au format AAAA-MM-JJ.')]
        public readonly ?string $date = null,
        #[Assert\Length(max: 255, maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.')]
        public readonly ?string $title = null,
        #[Assert\Choice(callback: [self::class, 'statuses'], message: 'Statut de séance inconnu.')]
        public readonly ?string $status = null,
        #[Assert\Regex(pattern: IsoDate::DATE_TIME_PATTERN, message: 'La date de début doit être une date ISO 8601.')]
        public readonly ?string $startedAt = null,
        #[Assert\Regex(pattern: IsoDate::DATE_TIME_PATTERN, message: 'La date de fin doit être une date ISO 8601.')]
        public readonly ?string $endedAt = null,
        public readonly ?string $completionNotes = null,
        #[Assert\Count(max: self::MAX_EXERCISES, maxMessage: 'Une séance ne peut pas porter plus de {{ limit }} exercices.')]
        #[Assert\Valid]
        public readonly array $log = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_column(ScheduledStatus::cases(), 'value');
    }

    /** Le document clôture-t-il la séance ? Le seul effet de `status` (cf. en-tête). */
    public function closes(): bool
    {
        return ScheduledStatus::DONE->value === $this->status;
    }
}
