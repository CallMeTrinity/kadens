<?php

namespace App\Tests\Enum;

use App\Enum\MuscleGroup;
use App\Enum\TargetArea;
use PHPUnit\Framework\TestCase;

/**
 * Le regroupement des zones en groupes d'entraînement.
 *
 * Ce que ces tests protègent : que le rattachement reste TOTAL. `of()` n'a pas
 * de branche par défaut, donc PHP refuserait de compiler une zone oubliée —
 * mais rien n'empêcherait quelqu'un d'en ajouter une en la rangeant dans OTHER
 * « en attendant ». Le test qui boucle sur `TargetArea::cases()` ne l'empêche
 * pas non plus ; il rend simplement l'ajout visible, et force à passer ici.
 */
final class MuscleGroupTest extends TestCase
{
    /**
     * Aucune zone ne lève, et le découpage couvre les cinq groupes : si une
     * refonte de TargetArea faisait disparaître le dernier représentant d'un
     * groupe, ce test tombe plutôt que de laisser une pastille orpheline dans
     * la légende.
     */
    public function testEveryTargetAreaMapsToAGroup(): void
    {
        $seen = [];

        foreach (TargetArea::cases() as $area) {
            $seen[MuscleGroup::of($area)->value] = true;
        }

        self::assertCount(
            \count(MuscleGroup::cases()),
            $seen,
            'Chaque groupe doit avoir au moins une zone, sinon sa pastille ne sort jamais.',
        );
    }

    /**
     * Le découpage suit l'usage de la salle, pas l'anatomie : c'est ce qui le
     * distingue de TargetRegion, et c'est la seule chose qui justifie qu'ils
     * coexistent. Les lombaires vont au dos, les trapèzes aussi, les avant-bras
     * aux bras.
     */
    public function testTheSplitFollowsTrainingUsageNotAnatomy(): void
    {
        self::assertSame(MuscleGroup::LEGS, MuscleGroup::of(TargetArea::QUADRICEPS));
        self::assertSame(MuscleGroup::LEGS, MuscleGroup::of(TargetArea::CALVES));
        self::assertSame(MuscleGroup::CHEST, MuscleGroup::of(TargetArea::CHEST));
        self::assertSame(MuscleGroup::BACK, MuscleGroup::of(TargetArea::BACK));
        self::assertSame(MuscleGroup::BACK, MuscleGroup::of(TargetArea::LOWER_BACK));
        self::assertSame(MuscleGroup::BACK, MuscleGroup::of(TargetArea::TRAPS));
        self::assertSame(MuscleGroup::ARMS, MuscleGroup::of(TargetArea::BICEPS));
        self::assertSame(MuscleGroup::ARMS, MuscleGroup::of(TargetArea::FOREARMS));
    }

    /**
     * Les épaules et le tronc tombent dans « Autres ». Décision assumée du
     * découpage demandé (jambes / pecs / dos / bras / autres) : la noter ici
     * rend explicite qu'un jour d'épaules porte la même pastille qu'un jour de
     * gainage, et rend le changement d'avis délibéré.
     */
    public function testShouldersAndCoreFallIntoOther(): void
    {
        self::assertSame(MuscleGroup::OTHER, MuscleGroup::of(TargetArea::SHOULDERS));
        self::assertSame(MuscleGroup::OTHER, MuscleGroup::of(TargetArea::ABS));
        self::assertSame(MuscleGroup::OTHER, MuscleGroup::of(TargetArea::OBLIQUES));
        self::assertSame(MuscleGroup::OTHER, MuscleGroup::of(TargetArea::FULL_BODY));
    }

    /**
     * Le `value` sert de suffixe de classe CSS (`.kd-muscle--legs`) : il doit
     * rester un identifiant CSS valide, sans underscore ni majuscule.
     */
    public function testValuesAreUsableAsCssModifiers(): void
    {
        foreach (MuscleGroup::cases() as $group) {
            self::assertMatchesRegularExpression('/^[a-z]+$/', $group->value);
            self::assertNotSame('', $group->getLabel());
        }
    }
}
