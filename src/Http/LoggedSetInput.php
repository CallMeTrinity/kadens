<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\SetType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une série réalisée, telle que le téléphone l'envoie (KL-16).
 *
 * **L'`uuid` est le pivot de tout le ticket** : c'est lui qui rend
 * `PUT /api/schedule/{uuid}` idempotent série par série, et il est posé par le
 * client, hors réseau, avant même que le serveur sache que la série existe.
 *
 * Deux choses n'ont **pas** de champ ici, et c'est délibéré :
 *
 * - **`position`** : l'ordre de la liste fait foi. Un rang envoyé à côté de
 *   l'ordre du tableau, c'est deux sources pour un seul fait, donc un jour deux
 *   réponses différentes à « quelle est la troisième série ». Le serveur
 *   renumérote, et c'est ce qu'il renvoie.
 * - **`tonnage`, `volume`, quoi que ce soit de dérivé** : le serveur les
 *   recalcule (`LogMetrics`, KL-03). Rien de calculable ne se transporte.
 *
 * Les bornes ne sont pas décoratives : elles transforment en 422 lisible ce qui
 * serait sinon une erreur SQL en 500 (`SMALLINT` dépassé) ou, pire, une donnée
 * absurde acceptée en silence et remontée ensuite comme record personnel.
 */
final class LoggedSetInput
{
    /** Une série de plus de 200 répétitions n'est pas une série, c'est une faute de frappe. */
    public const int MAX_REPS = 200;

    /** Au-delà d'une tonne, c'est le champ qui s'est trompé d'unité, pas l'athlète. */
    public const float MAX_WEIGHT_KG = 1000.0;

    /** Vingt-quatre heures : une borne de garde-fou, pas une opinion sur l'entraînement. */
    public const int MAX_DURATION_SECONDS = 86400;

    public function __construct(
        #[Assert\NotBlank(message: 'L\'identifiant de la série est requis.')]
        #[Assert\Uuid(message: 'L\'identifiant de la série doit être un UUID.')]
        public readonly ?string $uuid = null,
        // Une chaîne validée par `Choice` plutôt qu'un `SetType` typé : le
        // dénormaliseur refuserait bien une valeur inconnue, mais avec un message
        // écrit pour un développeur PHP. Le ticket demande un 422 qui dise quoi
        // corriger.
        #[Assert\Choice(callback: [self::class, 'setTypes'], message: 'Type de série inconnu.')]
        public readonly string $type = 'normal',
        #[Assert\Range(min: 0, max: self::MAX_REPS, notInRangeMessage: 'Le nombre de répétitions doit être compris entre {{ min }} et {{ max }}.')]
        public readonly ?int $reps = null,
        #[Assert\Range(min: 0, max: self::MAX_WEIGHT_KG, notInRangeMessage: 'La charge doit être comprise entre {{ min }} et {{ max }} kg.')]
        public readonly ?float $weightKg = null,
        #[Assert\Range(min: 0, max: self::MAX_DURATION_SECONDS, notInRangeMessage: 'La durée doit être comprise entre {{ min }} et {{ max }} secondes.')]
        public readonly ?int $durationSeconds = null,
        #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'Le RPE doit être compris entre {{ min }} et {{ max }}.')]
        public readonly ?int $rpe = null,
        #[Assert\Regex(pattern: IsoDate::DATE_TIME_PATTERN, message: 'La date d\'exécution doit être une date ISO 8601.')]
        public readonly ?string $completedAt = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function setTypes(): array
    {
        return array_column(SetType::cases(), 'value');
    }

    public function setType(): SetType
    {
        // Sûr : `Assert\Choice` a déjà tranché, et l'ingestion ne tourne pas sur
        // une charge utile invalide.
        return SetType::from($this->type);
    }
}
