<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Service\SupersetGrouper;
use PHPUnit\Framework\TestCase;

final class SupersetGrouperTest extends TestCase
{
    private SupersetGrouper $grouper;

    protected function setUp(): void
    {
        $this->grouper = new SupersetGrouper();
    }

    public function testSegmentsSplitABlockIntoSinglesAndLinkedGroups(): void
    {
        // A1/A2 liés, un isolé, puis B1/B2/B3 : un bloc porte plusieurs
        // enchaînements et des exercices seuls, ce que l'ancien modèle
        // (« bloc = superset ») ne savait pas exprimer.
        $block = $this->block([1, 1, null, 2, 2, 2]);

        $segments = $this->grouper->segments($block);

        self::assertCount(3, $segments);
        self::assertSame(['A', 'superset', 2], [$segments[0]['label'], $segments[0]['kind'], count($segments[0]['exercises'])]);
        self::assertSame([null, 'single', 1], [$segments[1]['label'], $segments[1]['kind'], count($segments[1]['exercises'])]);
        self::assertSame(['B', 'circuit', 3], [$segments[2]['label'], $segments[2]['kind'], count($segments[2]['exercises'])]);
    }

    public function testNormalizeDissolvesSingletonsAndRenumbersRuns(): void
    {
        // Groupe 7 orphelin (un seul membre) et groupe 4 en deux morceaux séparés :
        // après normalisation, seules les suites d'au moins deux membres survivent,
        // renumérotées 1..n dans l'ordre.
        $block = $this->block([7, 4, 4, null, 4, 4]);

        $this->grouper->normalize($block);

        self::assertSame([null, 1, 1, null, 2, 2], $this->groupsOf($block));
    }

    public function testLinkToPreviousOpensThenExtendsAGroup(): void
    {
        $block = $this->block([null, null, null]);
        $exercises = $block->getPrescribedExercises()->toArray();

        $this->grouper->linkToPrevious($exercises[1]);
        self::assertSame([1, 1, null], $this->groupsOf($block));

        $this->grouper->linkToPrevious($exercises[2]);
        self::assertSame([1, 1, 1], $this->groupsOf($block));
    }

    public function testLinkToPreviousDoesNothingAtTheTopOfTheBlock(): void
    {
        $block = $this->block([null, null]);

        $this->grouper->linkToPrevious($block->getPrescribedExercises()->first());

        self::assertSame([null, null], $this->groupsOf($block));
    }

    public function testLinkToPreviousMergesTwoAdjacentGroups(): void
    {
        $block = $this->block([1, 1, 2, 2]);
        $exercises = $block->getPrescribedExercises()->toArray();

        // Le premier membre du second groupe se lie au dernier du premier.
        $this->grouper->linkToPrevious($exercises[2]);

        self::assertSame([1, 1, 1, 1], $this->groupsOf($block));
    }

    public function testDetachExtractsFromTheMiddleWithoutBreakingTheRest(): void
    {
        // Détacher le milieu d'un tri-set : il passe après le groupe, les deux
        // autres restent liés (dissoudre l'ensemble serait une perte de saisie).
        $block = $this->block([1, 1, 1]);
        $exercises = $block->getPrescribedExercises()->toArray();
        $names = ['A', 'B', 'C'];
        foreach ($exercises as $i => $exercise) {
            $exercise->getExercise()->setName($names[$i]);
        }

        $this->grouper->detach($exercises[1]);

        self::assertSame(['A', 'C', 'B'], $this->names($block));
        self::assertSame([1, 1, null], $this->groupsOf($block));
    }

    public function testDetachOfATwoMemberGroupDissolvesIt(): void
    {
        $block = $this->block([1, 1]);

        $this->grouper->detach($block->getPrescribedExercises()->first());

        self::assertSame([null, null], $this->groupsOf($block));
    }

    public function testSettleAfterMoveJoinsAGroupWhenDroppedInsideIt(): void
    {
        // L'exercice isolé est glissé entre les deux membres du groupe : il les
        // rejoint (sinon la contiguïté du groupe serait cassée par un intrus).
        $block = $this->block([1, 1, null]);
        $exercises = $block->getPrescribedExercises()->toArray();
        $moved = $exercises[2];

        $moved->setPosition(0);
        $exercises[0]->setPosition(-1);

        $this->grouper->settleAfterMove($moved);

        self::assertSame([1, 1, 1], $this->groupsOf($block));
    }

    public function testSettleAfterMoveLeavesTheGroupWhenDraggedAway(): void
    {
        // Le premier membre du groupe part en fin de bloc : il en sort, et le
        // membre restant, seul, est dissous.
        $block = $this->block([1, 1, null]);
        $exercises = $block->getPrescribedExercises()->toArray();
        $moved = $exercises[0];

        $moved->setPosition(3);

        $this->grouper->settleAfterMove($moved);

        self::assertSame([null, null, null], $this->groupsOf($block));
    }

    /**
     * @param list<int|null> $groups
     */
    private function block(array $groups): Block
    {
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);

        foreach ($groups as $position => $group) {
            $exercise = (new Exercise())->setName('Ex'.$position)->setActivity(ActivityType::GYM);
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setPosition($position)
                    ->setSupersetGroup($group)
            );
        }

        return $block;
    }

    /**
     * Groupes dans l'ordre des positions (et non de la collection, que les
     * mutations ne réordonnent pas).
     *
     * @return list<int|null>
     */
    private function groupsOf(Block $block): array
    {
        return array_map(
            static fn (PrescribedExercise $pe) => $pe->getSupersetGroup(),
            $this->ordered($block),
        );
    }

    /**
     * @return list<string|null>
     */
    private function names(Block $block): array
    {
        return array_map(
            static fn (PrescribedExercise $pe) => $pe->getExercise()?->getName(),
            $this->ordered($block),
        );
    }

    /**
     * @return list<PrescribedExercise>
     */
    private function ordered(Block $block): array
    {
        $exercises = $block->getPrescribedExercises()->toArray();
        usort($exercises, static fn (PrescribedExercise $a, PrescribedExercise $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($exercises);
    }
}
