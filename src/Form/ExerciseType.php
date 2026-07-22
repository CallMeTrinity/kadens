<?php

namespace App\Form;

use App\Entity\Exercise;
use App\Enum\ActivityType;
use App\Enum\TargetArea;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExerciseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('activity', EnumType::class, [
                'class' => ActivityType::class,
                'label' => 'Activité',
                'choice_label' => fn (ActivityType $activity) => $activity->getLabel(),
                'placeholder' => 'Choisir une activité',
            ])
            ->add('targetAreas', EnumType::class, [
                'class' => TargetArea::class,
                'label' => 'Zones travaillées',
                'choice_label' => fn (TargetArea $area) => $area->getLabel(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('mediaUrl', UrlType::class, [
                'label' => 'Lien média',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Exercise::class,
        ]);
    }
}
