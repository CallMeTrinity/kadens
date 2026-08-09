<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\BodySilhouette;
use App\Enum\ExerciseLanguage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Les préférences d'affichage du compte : la langue des **noms d'exercices**, et
 * la silhouette dessinée par la carte musculaire.
 *
 * `expanded` plutôt qu'un `<select>` : deux valeurs mutuellement exclusives, une
 * pastille se lit et se touche mieux qu'une liste déroulante, et l'écran de
 * réglages n'a pas d'autre choix à faire tenir dans la même ligne.
 *
 * Pas de `placeholder` : les deux champs sont NOT NULL sur `User`. Un affichage
 * n'a pas de « non renseigné », contrairement au reste de la fiche athlète — et
 * c'est ce qui distingue `bodySilhouette` de `sex`, qui vit dans cette fiche.
 */
final class DisplaySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('exerciseLanguage', EnumType::class, [
            'class' => ExerciseLanguage::class,
            'label' => "Langue des noms d'exercices",
            'expanded' => true,
            'multiple' => false,
            'choice_label' => fn (ExerciseLanguage $language): string => $language->getLabel(),
            'help' => "Le reste de l'application reste en français.",
        ]);

        $builder->add('bodySilhouette', EnumType::class, [
            'class' => BodySilhouette::class,
            'label' => 'Silhouette de la carte musculaire',
            'expanded' => true,
            'multiple' => false,
            'choice_label' => fn (BodySilhouette $silhouette): string => $silhouette->getLabel(),
            'help' => "Le dessin des zones travaillées, dans le compositeur de séance. Sans rapport avec le sexe de la fiche athlète, qui sert au score de force.",
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
