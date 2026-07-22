<?php

namespace App\Form;

use App\Entity\Block;
use App\Enum\BlockRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('role', EnumType::class, [
                'class' => BlockRole::class,
                'label' => 'Rôle',
                'choice_label' => fn (BlockRole $role) => $role->getLabel(),
            ])
            ->add('rounds', IntegerType::class, [
                'label' => 'Tours',
                'attr' => ['min' => 1],
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Block::class,
        ]);
    }
}
