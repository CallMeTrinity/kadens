<?php

namespace App\Form;

use App\Entity\Goal;
use App\Enum\ActivityType;
use App\Enum\GoalOutcome;
use App\Enum\GoalPriority;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'objectif daté. Les champs de résultat (outcome / resultNote) ne
 * sont pertinents qu'une fois l'échéance passée : ils sont rendus dans une section
 * « Résultat » à part côté template.
 */
class GoalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Intitulé',
                'attr' => ['placeholder' => 'ex. Trail des Templiers 42 km'],
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'Date de l\'échéance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('activity', EnumType::class, [
                'class' => ActivityType::class,
                'label' => 'Activité',
                'choice_label' => fn (ActivityType $a): string => $a->getLabel(),
                'placeholder' => 'Transverse (aucune)',
                'required' => false,
            ])
            ->add('priority', EnumType::class, [
                'class' => GoalPriority::class,
                'label' => 'Priorité',
                'choice_label' => fn (GoalPriority $p): string => $p->getLabel(),
            ])
            ->add('targetValue', TextType::class, [
                'label' => 'Cible visée',
                'required' => false,
                'attr' => ['placeholder' => 'ex. sub 4h · 180 kg · Top 10'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Contexte, stratégie, parcours…'],
            ])
            ->add('outcome', EnumType::class, [
                'class' => GoalOutcome::class,
                'label' => 'Résultat',
                'choice_label' => fn (GoalOutcome $o): string => $o->getLabel(),
                'placeholder' => 'Pas encore renseigné',
                'required' => false,
            ])
            ->add('resultNote', TextareaType::class, [
                'label' => 'Débrief',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Comment ça s\'est passé ?'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Goal::class,
        ]);
    }
}
