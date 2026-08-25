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
 *
 * Les records de force saisis ici sont un **plancher**, pas la valeur affichée :
 * ils portent ce qui a été fait avant l'app ou hors séance loguée, et le réalisé
 * les dépasse dès qu'il les bat (`AthleteRecords`). C'est pourquoi ils restent
 * éditables alors que la fiche se met à jour toute seule.
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

        $reps = static fn (string $label, string $placeholder): array => [
            'label' => $label,
            'required' => false,
            'attr' => ['inputmode' => 'numeric', 'min' => 0, 'placeholder' => $placeholder],
        ];

        $zoneMax = static fn (string $label): array => [
            'label' => $label,
            'required' => false,
            'attr' => ['inputmode' => 'numeric', 'min' => 60, 'max' => 230, 'placeholder' => 'auto'],
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
            // Des répétitions au milieu des kilos : au poids du corps, le record
            // est un compte, pas une charge. Une série lestée ne l'alimente pas.
            ->add('maxPullups', IntegerType::class, $reps('Tractions (max)', 'ex. 14'))
            ->add('maxPushups', IntegerType::class, $reps('Pompes (max)', 'ex. 40'))
            ->add('maxDips', IntegerType::class, $reps('Dips (max)', 'ex. 17'))
            // Un temps, enfin : la suspension est un record de force (grip),
            // pas d'endurance, et se saisit donc ici en mm:ss.
            ->add('deadhangSeconds', DurationType::class, $time('Suspension à la barre', 'ex. 1:30'))
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
            // --- Zones cardio (BPM) ---
            // FC max/repos alimentent la dérivation auto (Karvonen). Les bornes de
            // zone sont facultatives : renseignées, elles surchargent la valeur
            // dérivée (placeholder = borne calculée) ; laissées vides, elles suivent
            // FC max/repos.
            ->add('maxHeartRate', IntegerType::class, [
                'label' => 'FC max (bpm)',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 100, 'max' => 230, 'placeholder' => 'ex. 190'],
            ])
            ->add('restingHeartRate', IntegerType::class, [
                'label' => 'FC repos (bpm)',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 30, 'max' => 120, 'placeholder' => 'ex. 50'],
            ])
            ->add('hrZone1Max', IntegerType::class, $zoneMax('Z1 · Récupération (max)'))
            ->add('hrZone2Max', IntegerType::class, $zoneMax('Z2 · Endurance (max)'))
            ->add('hrZone3Max', IntegerType::class, $zoneMax('Z3 · Tempo (max)'))
            ->add('hrZone4Max', IntegerType::class, $zoneMax('Z4 · Seuil (max)'))
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
