<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CustomMessage;
use App\Entity\User;
use App\Enum\MessageAudience;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire du back-office des messages programmés. L'image n'est pas mappée :
 * le contrôleur la confie à `MessageMediaStorage`, qui la range **sans la
 * ré-encoder** (un GIF garde son animation). D'où l'absence du contrôleur
 * `photo_upload` sur le champ, qui passe tout en JPEG.
 *
 * @extends AbstractType<CustomMessage>
 */
class CustomMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Nom interne',
                'help' => 'Pour t\'y retrouver ici. Les destinataires ne le voient jamais.',
                'attr' => ['placeholder' => 'ex. Anniversaire de Léa'],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'help' => 'Texte brut, les sauts de ligne sont conservés.',
                'attr' => ['rows' => 5],
            ])
            ->add('media', FileType::class, [
                'label' => $options['has_media'] ? 'Remplacer l\'image' : 'Image ou GIF',
                'mapped' => false,
                'required' => false,
                'help' => 'GIF, JPEG, PNG ou WebP, 8 Mo au plus. Envoyée telle quelle.',
                'attr' => ['accept' => 'image/gif,image/jpeg,image/png,image/webp'],
            ])
            ->add('startsOn', DateType::class, [
                'label' => 'Du',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endsOn', DateType::class, [
                'label' => 'Au (inclus)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('audience', EnumType::class, [
                'class' => MessageAudience::class,
                'label' => 'Pour',
                'choice_label' => fn (MessageAudience $a): string => $a->getLabel(),
                'expanded' => true,
            ])
            ->add('recipients', EntityType::class, [
                'class' => User::class,
                'label' => 'Destinataires',
                'help' => 'Ignoré quand le message est pour tout le monde.',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choice_label' => fn (User $user): string => $user->getDisplayName(),
                'query_builder' => fn (UserRepository $users): QueryBuilder => $users->createQueryBuilder('u')->orderBy('u.email', 'ASC'),
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
                'help' => 'Décoché, le message ne s\'affiche plus, sans rien perdre.',
            ])
        ;

        if ($options['has_media']) {
            $builder->add('removeMedia', CheckboxType::class, [
                'label' => 'Retirer l\'image',
                'mapped' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomMessage::class,
            'has_media' => false,
        ]);
        $resolver->setAllowedTypes('has_media', 'bool');
    }
}
