<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Le pseudo, seul. Servi à deux endroits : la section « Compte » des paramètres
 * et l'invitation qui s'ouvre tant qu'il n'y en a pas (`components/_username_prompt`).
 *
 * Les règles (longueur, caractères, unicité) sont sur l'entité : elles valent
 * d'où que vienne la valeur. Seule différence entre les deux usages, l'option
 * `required` : dans les paramètres, vider le champ retire le pseudo ; dans
 * l'invitation, un envoi à vide ne veut rien dire — refuser, c'est « Non merci ».
 *
 * @extends AbstractType<User>
 */
final class UsernameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', TextType::class, [
            'label' => 'Pseudo',
            'required' => $options['required'],
            // Le NotBlank ne vaut que pour l'invitation : `required` seul n'est
            // qu'un attribut HTML, que rien n'oblige le client à respecter.
            'constraints' => $options['required'] ? [new NotBlank(message: 'Choisis un pseudo, ou réponds « Non merci ».')] : [],
            'help' => 'Affiché à la place de ton email. Tu te connectes toujours avec ton email, et on t\'invite en coaching avec lui.',
            'attr' => [
                'maxlength' => User::USERNAME_MAX_LENGTH,
                'autocomplete' => 'nickname',
                'autocapitalize' => 'none',
                'spellcheck' => 'false',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'required' => false,
        ]);
    }
}
