<?php

namespace App\Form;

use App\Entity\User;
use App\Enum\Sex;
use App\Enum\TrainingGoal;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fiche athlète éditable. Tous les champs sont facultatifs (la fiche se remplit
 * progressivement). Unités normalisées : force en kg (NumberType), temps en
 * secondes via DurationType (saisie mm:ss / h:mm:ss).
 */
class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $kg = static fn (string $label, string $placeholder): array => [
            'label' => $label,
            'required' => false,
            'scale' => 2,
            'html5' => true,
            'attr' => ['inputmode' => 'decimal', 'step' => '0.5', 'min' => 0, 'placeholder' => $placeholder],
        ];

        $time = static fn (string $label, string $placeholder): array => [
            'label' => $label,
            'required' => false,
            'attr' => ['placeholder' => $placeholder],
        ];

        $builder
            // --- Identité ---
            ->add('birthDate', DateType::class, [
                'label' => 'Date de naissance',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('sex', EnumType::class, [
                'label' => 'Sexe',
                'class' => Sex::class,
                'required' => false,
                'placeholder' => 'Non renseigné',
                'choice_label' => fn (Sex $s): string => $s->getLabel(),
            ])
            ->add('heightCm', IntegerType::class, [
                'label' => 'Taille (cm)',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 0, 'placeholder' => 'ex. 178'],
            ])
            ->add('weightKg', NumberType::class, $kg('Poids (kg)', 'ex. 74'))
            ->add('trainingYears', IntegerType::class, [
                'label' => "Années d'entraînement",
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 0, 'placeholder' => 'ex. 5'],
            ])
            ->add('mainGoal', EnumType::class, [
                'label' => 'Objectif principal',
                'class' => TrainingGoal::class,
                'required' => false,
                'placeholder' => 'Non renseigné',
                'choice_label' => fn (TrainingGoal $g): string => $g->getLabel(),
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Bio',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Quelques mots sur ta pratique…'],
            ])
            // --- Records de force (1RM, kg) ---
            ->add('squat1rmKg', NumberType::class, $kg('Squat (1RM)', 'ex. 140'))
            ->add('bench1rmKg', NumberType::class, $kg('Développé couché (1RM)', 'ex. 100'))
            ->add('deadlift1rmKg', NumberType::class, $kg('Soulevé de terre (1RM)', 'ex. 180'))
            ->add('ohp1rmKg', NumberType::class, $kg('Développé militaire (1RM)', 'ex. 60'))
            ->add('weightedPullupKg', NumberType::class, $kg('Traction lestée (poids ajouté)', 'ex. 30'))
            // --- Records d'endurance ---
            ->add('run5kSeconds', DurationType::class, $time('5 km', 'ex. 21:30'))
            ->add('run10kSeconds', DurationType::class, $time('10 km', 'ex. 45:00'))
            ->add('halfMarathonSeconds', DurationType::class, $time('Semi-marathon', 'ex. 1:42:00'))
            ->add('marathonSeconds', DurationType::class, $time('Marathon', 'ex. 3:45:00'))
            ->add('cyclingFtpWatts', IntegerType::class, [
                'label' => 'FTP vélo (watts)',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 0, 'placeholder' => 'ex. 240'],
            ])
            ->add('swim100mSeconds', DurationType::class, $time('100 m natation', 'ex. 1:35'))
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
