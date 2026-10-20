<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\ExerciseSetup;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le réglage de machine tel que le téléphone l'envoie à
 * `PUT /api/exercises/{id}/setup` : **le texte entier**, jamais un morceau.
 *
 * `null`, une chaîne vide ou faite d'espaces veulent tous dire « plus de
 * réglage » : le contrôleur supprime la ligne. Trois façons de dire la même
 * chose, une seule écriture en base.
 */
final class ExerciseSetupInput
{
    public function __construct(
        #[Assert\Length(max: ExerciseSetup::MAX_LENGTH, maxMessage: 'Le réglage ne peut pas dépasser {{ limit }} caractères.')]
        public readonly ?string $text = null,
    ) {
    }

    /** Le texte à écrire, ou `null` s'il n'y a rien à garder. */
    public function normalized(): ?string
    {
        $text = trim($this->text ?? '');

        return '' === $text ? null : $text;
    }
}
