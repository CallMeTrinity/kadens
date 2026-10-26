<?php

namespace App\Twig;

use App\Entity\CustomMessage;
use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\Feeling;
use App\Enum\PrescriptionType;
use App\Form\UsernameType;
use App\Http\BackTarget;
use App\Service\ActivityPalette;
use App\Service\BackLink;
use App\Service\CustomMessageInbox;
use App\Service\ExerciseNaming;
use App\Service\SessionRating;
use App\Service\UnitFormatter;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
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
 * - `activity_palette_css()` rend les couleurs d'activité choisies par le
 *   lecteur, en déclarations `:root` à poser dans `<head>` (`base.html.twig`).
 *   Valeurs déjà validées par `ActivityPalette` (hex strict), d'où le `raw`.
 * - `|tonnage` formate un tonnage en kilos, ou en tonnes au-delà de 100 000 kg
 *   (`UnitFormatter::tonnage()`). Un template qui écrirait encore
 *   `|number_format ~ ' kg'` afficherait « 612 480 kg » là où le reste de l'app
 *   dit « 612,5 t ».
 * - `username_prompt_form()` fabrique le formulaire de l'invitation à choisir un
 *   pseudo, que `base.html.twig` pose sur toutes les pages : aucun contrôleur
 *   n'a à le passer. Null quand il n'y a rien à demander.
 * - `pending_custom_message()` rend le message programmé à montrer aujourd'hui
 *   (`base.html.twig`), `custom_message_media_url()` l'URL signée de son image
 *   (modale et aperçu du back-office). Cf. `CustomMessageInbox`.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExerciseNaming $naming,
        private readonly BackLink $backLink,
        private readonly ActivityPalette $activityPalette,
        private readonly UnitFormatter $units,
        private readonly FormFactoryInterface $forms,
        private readonly CustomMessageInbox $inbox,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('tonnage', [$this->units, 'tonnage']),
        ];
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
            new TwigFunction('activity_palette_css', [$this, 'activityPaletteCss'], ['is_safe' => ['html']]),
            new TwigFunction('username_prompt_form', [$this, 'usernamePromptForm']),
            new TwigFunction('pending_custom_message', [$this, 'pendingCustomMessage']),
            new TwigFunction('custom_message_media_url', [$this->inbox, 'mediaUrl']),
            // Les libellés du ressenti de séance, pour une valeur ou une moyenne
            // (le cran le plus proche) : un seul endroit les écrit, `Feeling` et
            // `SessionRating`, et le sélecteur comme le badge les lisent ici.
            new TwigFunction('feeling_label', [Feeling::class, 'labelOf']),
            new TwigFunction('effort_label', [SessionRating::class, 'effortLabelOf']),
        ];
    }

    /**
     * Même règle que la palette : tout ce qui n'est pas un `User` (anonyme
     * compris) n'a rien à choisir.
     */
    public function usernamePromptForm(mixed $user, string $action): ?FormView
    {
        if (!$user instanceof User || !$user->shouldPromptForUsername()) {
            return null;
        }

        return $this->forms->create(UsernameType::class, $user, ['required' => true, 'action' => $action])->createView();
    }

    /** Même règle : seul un `User` reçoit des messages. */
    public function pendingCustomMessage(mixed $user): ?CustomMessage
    {
        return $user instanceof User ? $this->inbox->pendingFor($user) : null;
    }

    /**
     * `app.user` peut être autre chose qu'un `User` en théorie (UserInterface) :
     * tout ce qui n'en est pas un lit la palette par défaut.
     */
    public function activityPaletteCss(mixed $user): ?string
    {
        return $this->activityPalette->cssOverrides($user instanceof User ? $user : null);
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
