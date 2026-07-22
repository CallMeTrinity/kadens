<?php

namespace App\Form;

use App\Entity\PlanItem;
use App\Entity\Workout;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Placement d'une séance existante dans une case de la trame (une semaine × un
 * jour). La position (semaine/jour) est portée par la route, pas par le
 * formulaire.
 *
 * Les choix de séances sont préchargés une seule fois par le contrôleur et
 * passés via l'option `workouts` : la grille rend une case par jour, donc
 * réutiliser la même liste évite autant de requêtes qu'il y a de cases.
 */
class PlanItemType extends AbstractType
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
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlanItem::class,
            'workouts' => [],
        ]);
        $resolver->setAllowedTypes('workouts', 'array');
    }
}
