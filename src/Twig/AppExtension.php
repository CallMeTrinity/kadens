<?php

namespace App\Twig;

use App\Entity\Exercise;
use App\Enum\PrescriptionType;
use App\Service\ExerciseNaming;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig transverses.
 *
 * - `prescription_type_fields_map()` expose la carte type -> champs pertinents
 *   au contrôleur Stimulus d'affichage dynamique, sans dupliquer la logique
 *   définie sur l'enum PrescriptionType.
 * - `exercise_name()` / `exercise_alt_name()` / `exercise_search_text()` sont le
 *   seul accès au libellé d'un exercice depuis un template : la langue choisie,
 *   les replis et le cas « exercice supprimé » vivent dans `ExerciseNaming`. Un
 *   template qui écrirait `exercise.name` en direct court-circuiterait la
 *   préférence de l'utilisateur.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExerciseNaming $naming,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('prescription_type_fields_map', [$this, 'prescriptionTypeFieldsMap']),
            new TwigFunction('exercise_name', [$this->naming, 'label']),
            new TwigFunction('exercise_alt_name', [$this->naming, 'alternate']),
            new TwigFunction('exercise_search_text', [$this, 'exerciseSearchText']),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function prescriptionTypeFieldsMap(): array
    {
        return PrescriptionType::fieldsMap();
    }

    public function exerciseSearchText(?Exercise $exercise): string
    {
        return null === $exercise ? '' : $this->naming->searchText($exercise);
    }
}
