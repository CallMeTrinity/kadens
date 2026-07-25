<?php

namespace App\Form;

use App\Service\UnitFormatter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une durée/temps chronométré en `mm:ss` ou `h:mm:ss`, transformée
 * vers/depuis un nombre de secondes (unité normalisée en base). Calqué sur
 * PaceType : parent TextType + CallbackTransformer. L'affichage retour réutilise
 * UnitFormatter::duration (source unique du formatage mm:ss / h:mm:ss).
 *
 * Sert aux records de course (5K/10K/semi/marathon) et au temps 100m natation.
 */
final class DurationType extends AbstractType
{
    public function __construct(
        private readonly UnitFormatter $unitFormatter,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            fn (?int $seconds): string => null === $seconds ? '' : $this->unitFormatter->duration($seconds),
            static fn (?string $text): ?int => self::parse($text),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('invalid_message', 'Temps invalide (attendu mm:ss ou h:mm:ss).');
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    /**
     * `h:mm:ss`, `mm:ss` ou un nombre simple (interprété en minutes) -> secondes.
     * Chaîne vide -> null. Format inattendu -> exception (message d'invalidité).
     */
    private static function parse(?string $text): ?int
    {
        $text = trim((string) $text);
        if ('' === $text) {
            return null;
        }

        // Nombre simple ("21" -> 21 min, "21,5" -> 21:30).
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $text)) {
            return (int) round(((float) str_replace(',', '.', $text)) * 60);
        }

        $parts = explode(':', $text);
        if (\count($parts) < 2 || \count($parts) > 3) {
            throw new \Symfony\Component\Form\Exception\TransformationFailedException('Format de temps invalide.');
        }

        foreach ($parts as $part) {
            if (!preg_match('/^\d+$/', trim($part))) {
                throw new \Symfony\Component\Form\Exception\TransformationFailedException('Format de temps invalide.');
            }
        }

        $parts = array_map('intval', $parts);
        if (2 === \count($parts)) {
            [$minutes, $seconds] = $parts;

            return $minutes * 60 + $seconds;
        }

        [$hours, $minutes, $seconds] = $parts;

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}
