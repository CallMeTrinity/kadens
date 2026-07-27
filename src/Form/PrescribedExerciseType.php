<?php

namespace App\Form;

use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\DistanceUnit;
use App\Enum\IntensityZone;
use App\Enum\PaceUnit;
use App\Enum\PrescriptionType;
use App\Service\HeartRateZones;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un exercice prescrit dans un bloc. Tous les champs de valeurs sont exposés ;
 * seul le sous-ensemble pertinent (cf. PrescriptionType::fields()) est affiché
 * côté client et conservé côté serveur. L'exercice lui-même n'est plus modifiable
 * ici (on n'échange pas un exo une fois posé) : il est affiché en lecture seule.
 *
 * Option `detailed` : l'exercice porte des PrescribedSet, qui priment sur les
 * valeurs scalaires par série. Les champs correspondants (reps/charge/durée) ne
 * sont alors PAS déclarés du tout. Les sauter dans le template ne suffisait pas :
 * `form_end()` appelle `form_rest()`, qui les re-rendait en fin de formulaire,
 * hors des cibles `prescription-fields` (donc jamais masqués par le type d'effort)
 * et en double saisie contradictoire avec l'éditeur de séries.
 * `sets`, lui, reste déclaré : c'est le compteur de séries de travail, synchronisé
 * dans les deux sens avec la collection détaillée (cf. SetSynchronizer).
 */
class PrescribedExerciseType extends AbstractType
{
    public function __construct(
        private readonly HeartRateZones $heartRateZones,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['user'];
        $paceUnit = PaceUnit::forActivity($options['activity']);
        $distanceUnit = DistanceUnit::forActivity($options['activity']);

        $builder
            ->add('prescriptionType', EnumType::class, [
                'class' => PrescriptionType::class,
                'label' => 'Type d\'effort',
                'choice_label' => fn (PrescriptionType $type) => $type->getLabel(),
            ])
            ->add('sets', IntegerType::class, [
                'label' => 'Séries',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('distanceMeters', DistanceType::class, [
                // Unité déduite de l'activité de l'exercice prescrit (course/vélo
                // en km, natation et reste en mètres).
                'label' => 'Distance ('.$distanceUnit->label().')',
                'unit' => $distanceUnit,
                'required' => false,
                'attr' => ['placeholder' => $distanceUnit->placeholder()],
            ])
            ->add('paceSecondsPerKm', PaceType::class, [
                // Unité déduite de l'activité de l'exercice prescrit (course
                // min/km, vélo km/h, natation min/100m).
                'label' => 'Allure ('.$paceUnit->label().')',
                'unit' => $paceUnit,
                'required' => false,
                'attr' => ['placeholder' => $paceUnit->placeholder()],
            ])
            ->add('targetReps', IntegerType::class, [
                'label' => 'Répétitions cible',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('capSeconds', IntegerType::class, [
                'label' => 'Temps limite (s)',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('intensityZone', ChoiceType::class, [
                // Choix Z1..Z5 (valeurs = IntensityZone->value, stockées telles
                // quelles dans la colonne string). Les BPM affichés dans le libellé
                // viennent des zones du profil (service HeartRateZones), donc chaque
                // athlète voit ses propres repères.
                'label' => 'Zone d\'intensité',
                'required' => false,
                'placeholder' => '—',
                'choices' => $this->intensityChoices($user),
            ])
            ->add('elevationGainMeters', IntegerType::class, [
                'label' => 'Dénivelé + (m)',
                'required' => false,
                'attr' => ['min' => 0, 'step' => 10],
            ])
            ->add('rpe', IntegerType::class, [
                // Effort ressenti 1-10 (transverse à tous les types d'effort).
                'label' => 'RPE (1-10)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 10],
            ])
            ->add('restSeconds', IntegerType::class, [
                'label' => 'Repos (s)',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
            ])
        ;

        // Valeurs par série : portées par la collection détaillée quand elle
        // existe, donc absentes du formulaire dans ce cas (cf. docblock).
        if (!$options['detailed']) {
            $builder
                ->add('reps', IntegerType::class, [
                    'label' => 'Répétitions',
                    'required' => false,
                    'attr' => ['min' => 0],
                ])
                ->add('weightKg', NumberType::class, [
                    'label' => 'Charge (kg)',
                    'required' => false,
                    'scale' => 2,
                    'attr' => ['min' => 0, 'step' => 0.5],
                ])
                ->add('durationSeconds', DurationType::class, [
                    // Saisie humaine mm:ss ou h:mm:ss (round-trip vers les secondes
                    // stockées) : « 45:00 » plutôt que 2700.
                    'label' => 'Durée (h:mm:ss)',
                    'required' => false,
                    'attr' => ['placeholder' => '45:00'],
                ])
            ;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PrescribedExercise::class,
        ]);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
        // Activité de l'exercice prescrit : pilote l'unité d'allure. Null (ex.
        // formulaire d'ajout où l'exercice n'est pas encore choisi) -> min/km.
        $resolver->setDefault('activity', null);
        $resolver->setAllowedTypes('activity', ['null', ActivityType::class]);
        // L'exercice porte des séries détaillées : les valeurs par série sortent
        // du formulaire (cf. docblock de la classe).
        $resolver->setDefault('detailed', false);
        $resolver->setAllowedTypes('detailed', 'bool');
    }

    /**
     * Libellés de zone enrichis des BPM du profil : « Z4 · Seuil (146-160 bpm) ».
     * Sans FC max renseignée, la fourchette est omise (« Z4 · Seuil »).
     *
     * @return array<string, string> libellé => valeur d'IntensityZone
     */
    private function intensityChoices(User $user): array
    {
        $choices = [];
        foreach ($this->heartRateZones->forUser($user) as $band) {
            $zone = $band['zone'];
            $label = sprintf('%s · %s', strtoupper($zone->value), $zone->label());

            if (null !== $band['min'] && null !== $band['max']) {
                $label .= sprintf(' (%d-%d bpm)', $band['min'], $band['max']);
            }

            $choices[$label] = $zone->value;
        }

        return $choices;
    }
}
