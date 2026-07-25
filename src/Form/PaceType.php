<?php

namespace App\Form;

use App\Enum\PaceUnit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une allure dans l'unité naturelle de l'activité (option `unit`) :
 * min/km pour la course, km/h pour le vélo, min/100m pour la natation. La valeur
 * est transformée vers/depuis les secondes par km stockées en base (unité
 * normalisée, cf. PaceUnit). L'utilisateur ne fait jamais la conversion lui-même.
 *
 * Pour les unités « m:ss », un nombre simple est aussi accepté et interprété en
 * minutes (« 5 » -> 5:00, « 5,5 » -> 5:30).
 */
final class PaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var PaceUnit $unit */
        $unit = $options['unit'];

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $seconds): string => null === $seconds ? '' : $unit->toInputValue($seconds),
            static fn (?string $text): ?int => $unit->toSecondsPerKm((string) $text),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('unit', PaceUnit::MIN_PER_KM);
        $resolver->setAllowedTypes('unit', PaceUnit::class);
        $resolver->setDefault('invalid_message', 'Valeur invalide.');
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
