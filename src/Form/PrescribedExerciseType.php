<?php

namespace App\Form;

use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\DistanceUnit;
use App\Enum\PaceUnit;
use App\Enum\PrescriptionType;
use App\Repository\ExerciseRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un exercice prescrit dans un bloc. Tous les champs de valeurs sont exposés ;
 * seul le sous-ensemble pertinent (cf. PrescriptionType::fields()) est affiché
 * côté client et conservé côté serveur.
 */
class PrescribedExerciseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['user'];
        $paceUnit = PaceUnit::forActivity($options['activity']);
        $distanceUnit = DistanceUnit::forActivity($options['activity']);

        $builder
            ->add('exercise', EntityType::class, [
                'class' => Exercise::class,
                'label' => 'Exercice',
                'choice_label' => 'name',
                'placeholder' => 'Choisir un exercice',
                'query_builder' => fn (ExerciseRepository $repository) => $repository->createLibraryQueryBuilder($user),
            ])
            ->add('prescriptionType', EnumType::class, [
                'class' => PrescriptionType::class,
                'label' => 'Type d\'effort',
                'choice_label' => fn (PrescriptionType $type) => $type->getLabel(),
            ])
            ->add('sets', IntegerType::class, [
                'label' => 'Séries',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('reps', IntegerType::class, [
                'label' => 'Répétitions',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('weightKg', NumberType::class, [
                'label' => 'Charge (kg)',
                'required' => false,
                'scale' => 2,
                'attr' => ['min' => 0, 'step' => 0.5],
            ])
            ->add('durationSeconds', IntegerType::class, [
                'label' => 'Durée (s)',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('distanceMeters', DistanceType::class, [
                // Unité déduite de l'activité de l'exercice prescrit (course/vélo
                // en km, natation et reste en mètres).
                'label' => 'Distance ('.$distanceUnit->label().')',
                'unit' => $distanceUnit,
                'required' => false,
                'attr' => ['placeholder' => $distanceUnit->placeholder()],
            ])
            ->add('paceSecondsPerKm', PaceType::class, [
                // Unité déduite de l'activité de l'exercice prescrit (course
                // min/km, vélo km/h, natation min/100m).
                'label' => 'Allure ('.$paceUnit->label().')',
                'unit' => $paceUnit,
                'required' => false,
                'attr' => ['placeholder' => $paceUnit->placeholder()],
            ])
            ->add('targetReps', IntegerType::class, [
                'label' => 'Répétitions cible',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('capSeconds', IntegerType::class, [
                'label' => 'Temps limite (s)',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('intensityZone', TextType::class, [
                'label' => 'Zone d\'intensité',
                'required' => false,
            ])
            ->add('restSeconds', IntegerType::class, [
                'label' => 'Repos (s)',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PrescribedExercise::class,
        ]);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
        // Activité de l'exercice prescrit : pilote l'unité d'allure. Null (ex.
        // formulaire d'ajout où l'exercice n'est pas encore choisi) -> min/km.
        $resolver->setDefault('activity', null);
        $resolver->setAllowedTypes('activity', ['null', ActivityType::class]);
    }
}
