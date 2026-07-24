<?php

namespace App\Form;

use App\Entity\PlanTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'instanciation d'un plan : quel PlanTemplate, à partir de quelle
 * date. Non lié à une entité (les données alimentent PlanScheduler, qui
 * produit N ScheduledWorkout). La date de départ est ancrée au lundi de sa
 * semaine ISO côté service.
 *
 * Les plans sont préchargés une fois par le contrôleur et passés via l'option
 * `planTemplates`.
 */
class PlanInstantiationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('planTemplate', EntityType::class, [
                'class' => PlanTemplate::class,
                'label' => 'Plan',
                'choice_label' => 'title',
                'placeholder' => 'Choisir un plan',
                'choices' => $options['planTemplates'],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Date de départ',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'planTemplates' => [],
        ]);
        $resolver->setAllowedTypes('planTemplates', 'array');
    }
}
