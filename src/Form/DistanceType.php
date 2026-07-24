<?php

namespace App\Form;

use App\Enum\DistanceUnit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une distance dans l'unité naturelle de l'activité (option `unit`) :
 * km pour la course et le vélo, mètres pour la natation (et le reste). La valeur
 * est transformée vers/depuis les mètres stockés en base (unité normalisée, cf.
 * DistanceUnit). L'utilisateur ne fait jamais la conversion lui-même.
 */
final class DistanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var DistanceUnit $unit */
        $unit = $options['unit'];

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $meters): string => null === $meters ? '' : $unit->toInputValue($meters),
            static fn (?string $text): ?int => $unit->toMeters((string) $text),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('unit', DistanceUnit::METERS);
        $resolver->setAllowedTypes('unit', DistanceUnit::class);
        $resolver->setDefault('invalid_message', 'Distance invalide.');
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
