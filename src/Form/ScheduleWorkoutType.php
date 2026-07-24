<?php

namespace App\Form;

use App\Entity\ScheduledWorkout;
use App\Entity\Workout;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pose une séance existante sur une date précise, hors de tout plan. Le
 * propriétaire et le statut (PLANNED) sont fixés par le contrôleur.
 *
 * Les choix de séances sont préchargés une fois par le contrôleur et passés via
 * l'option `workouts` (précharge unique pour éviter un N+1 par choix).
 */
class ScheduleWorkoutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workout', EntityType::class, [
                'class' => Workout::class,
                'label' => 'Séance',
                'choice_label' => 'title',
                'placeholder' => 'Choisir une séance',
                'choices' => $options['workouts'],
            ])
            ->add('scheduledDate', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ScheduledWorkout::class,
            'workouts' => [],
        ]);
        $resolver->setAllowedTypes('workouts', 'array');
    }
}
