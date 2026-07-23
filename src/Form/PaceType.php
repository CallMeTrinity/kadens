<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une allure au format min/km (« m:ss », ex. « 5:30 »), transformée
 * vers/depuis un nombre de secondes par km (unité normalisée en base). L'idée :
 * l'utilisateur ne fait jamais la conversion en secondes lui-même.
 *
 * Un nombre simple est aussi accepté et interprété en minutes (« 5 » -> 5:00,
 * « 5,5 » -> 5:30).
 */
final class PaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static function (?int $seconds): string {
                if (null === $seconds) {
                    return '';
                }

                return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
            },
            static function (?string $text): ?int {
                $text = trim((string) $text);
                if ('' === $text) {
                    return null;
                }

                // Format « m:ss » (allure par kilomètre).
                if (preg_match('/^(\d+):([0-5]?\d)$/', $text, $matches)) {
                    return (int) $matches[1] * 60 + (int) $matches[2];
                }

                // Nombre simple interprété en minutes (« 5 », « 5,5 »).
                $normalized = str_replace(',', '.', $text);
                if (is_numeric($normalized)) {
                    return (int) round((float) $normalized * 60);
                }

                throw new TransformationFailedException('Allure invalide.');
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('invalid_message', 'Allure invalide. Utilise le format min:sec (ex. 5:30).');
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
