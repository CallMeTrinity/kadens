<?php

namespace App\Twig;

use App\Entity\Exercise;
use App\Enum\PrescriptionType;
use App\Http\BackTarget;
use App\Service\BackLink;
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
 * - `back_to()` / `back_carry()` / `back_link()` sont les trois temps de l'ancre
 *   de contexte (cf. `App\Http\BackTarget`) : poser le contexte sur un lien
 *   sortant, le reconduire d'un saut au suivant, l'afficher. `back_link()` sert
 *   le composant `components/_backlink.html.twig` et n'a pas à être appelé
 *   ailleurs.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExerciseNaming $naming,
        private readonly BackLink $backLink,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('prescription_type_fields_map', [$this, 'prescriptionTypeFieldsMap']),
            new TwigFunction('exercise_name', [$this->naming, 'label']),
            new TwigFunction('exercise_alt_name', [$this->naming, 'alternate']),
            new TwigFunction('exercise_search_text', [$this, 'exerciseSearchText']),
            new TwigFunction('back_to', [$this->backLink, 'to']),
            new TwigFunction('back_carry', [$this->backLink, 'carry']),
            new TwigFunction('back_link', [$this->backLink, 'resolve']),
            // Le jeton nu, pour les rares cas où il ne part pas dans une URL :
            // le calendrier le passe à sa pastille, qui le pose sur trois liens
            // et le reconduit par champ caché à travers le flux Turbo.
            new TwigFunction('back_token', [BackTarget::class, 'token']),
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
