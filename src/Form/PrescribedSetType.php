<?php

namespace App\Form;

use App\Entity\PrescribedSet;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une série individuelle en mode « séries détaillées ». Le champ de valeur exposé
 * dépend du type de force parent (reps pour SETS_REPS, durée pour SETS_TIME) : on
 * n'affiche jamais un champ hors-sujet. Chaque champ s'auto-enregistre au
 * changement (le compositeur intercepte la soumission), donc pas de bouton.
 */
class PrescribedSetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('setType', EnumType::class, [
            'class' => SetType::class,
            'label' => 'Type de série',
            'choice_label' => fn (SetType $type) => $type->getLabel(),
        ]);

        if (PrescriptionType::SETS_TIME === $options['parent_type']) {
            $builder->add('durationSeconds', DurationType::class, [
                'label' => 'Durée (mm:ss)',
                'required' => false,
                'attr' => ['placeholder' => '0:45'],
            ]);
        } else {
            $builder->add('reps', IntegerType::class, [
                'label' => 'Reps',
                'required' => false,
                'attr' => ['min' => 0],
            ]);
        }

        $builder->add('weightKg', NumberType::class, [
            'label' => 'Charge (kg)',
            'required' => false,
            'scale' => 2,
            'attr' => ['min' => 0, 'step' => 0.5],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PrescribedSet::class,
            // Type de force parent : pilote le champ de valeur affiché.
            'parent_type' => PrescriptionType::SETS_REPS,
        ]);
        $resolver->setAllowedTypes('parent_type', PrescriptionType::class);
    }
}
