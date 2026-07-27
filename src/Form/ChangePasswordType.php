<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Changement de mot de passe depuis les paramètres du profil. Aucun champ n'est
 * mappé sur `User` : le hachage se fait dans le contrôleur (le formulaire ne
 * manipule que du clair). `UserPassword` vérifie l'ancien mot de passe contre
 * l'utilisateur connecté — le formulaire n'a donc de sens que dans le firewall.
 */
class ChangePasswordType extends AbstractType
{
    /** Doit rester aligné avec `App\Command\CreateUserCommand`. */
    public const MIN_LENGTH = 8;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password', 'placeholder' => '••••••••'],
                'constraints' => [
                    new NotBlank(message: 'Saisis ton mot de passe actuel.'),
                    new UserPassword(message: 'Ce mot de passe ne correspond pas à ton mot de passe actuel.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'help' => sprintf('Au moins %d caractères.', self::MIN_LENGTH),
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => '••••••••'],
                ],
                'second_options' => [
                    'label' => 'Répéter le nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => '••••••••'],
                ],
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'constraints' => [
                    new NotBlank(message: 'Choisis un nouveau mot de passe.'),
                    new Length(
                        min: self::MIN_LENGTH,
                        max: 4096, // garde-fou : le hachage est coûteux sur une entrée longue
                        minMessage: sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_LENGTH),
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
