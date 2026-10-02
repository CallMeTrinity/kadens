<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\ActivityType;
use App\Service\ActivityPalette;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une couleur par activité, pour les réglages d'affichage.
 *
 * **Deux formes, une frontière.** Le formulaire montre la palette COMPLÈTE (un
 * `<input type="color">` par activité, pré-rempli) ; `User.activityColors` ne
 * garde que les ÉCARTS au défaut. Le transformeur fait le passage dans les deux
 * sens (`ActivityPalette::complete` / `overridesOf`) : choisir la couleur par
 * défaut, c'est retirer la clé — rien à nettoyer côté contrôleur.
 *
 * La garde de lisibilité vit ici, au plus près de la saisie : une couleur
 * libre sous 4,5:1 avec le blanc est refusée avec son contraste mesuré, pour
 * que l'utilisateur sache de combien il doit assombrir. Les pastilles
 * (`ActivityPalette::SWATCHES`) passent toutes.
 */
final class ActivityColorsType extends AbstractType
{
    public function __construct(
        private readonly ActivityPalette $palette,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (ActivityType::cases() as $activity) {
            $builder->add($activity->value, ColorType::class, [
                'label' => $activity->getLabel(),
                // `html5` : le type vérifie lui-même le format `#rrggbb`.
                'html5' => true,
                'invalid_message' => 'Couleur invalide (attendu #rrggbb).',
                'constraints' => [new Callback($this->assertReadable(...))],
            ]);
        }

        $builder->addModelTransformer(new CallbackTransformer(
            fn (?array $overrides): array => $this->palette->complete($overrides ?? []),
            fn (?array $palette): array => $this->palette->overridesOf($palette ?? []),
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Ce que le gabarit ne peut pas déduire des champs : l'activité (pour
        // l'icône et la classe de couleur), les pastilles et le défaut de
        // chaque ligne (bouton « Par défaut »).
        $view->vars['activities'] = ActivityType::cases();
        $view->vars['swatches'] = ActivityPalette::SWATCHES;
        $view->vars['defaults'] = ActivityPalette::DEFAULTS;
    }

    public function assertReadable(mixed $color, ExecutionContextInterface $context): void
    {
        // Format invalide : `invalid_message` a déjà parlé.
        if (!ActivityPalette::isValidHex($color) || $this->palette->isReadable($color)) {
            return;
        }

        $context->buildViolation('Trop clair pour du texte blanc : contraste de {{ ratio }}:1, il en faut {{ min }}:1.')
            ->setParameter('{{ ratio }}', number_format($this->palette->contrastWithWhite($color), 1, ',', ''))
            ->setParameter('{{ min }}', number_format(ActivityPalette::MIN_CONTRAST, 1, ',', ''))
            ->addViolation();
    }
}
