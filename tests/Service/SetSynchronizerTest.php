<?php

namespace App\Tests\Service;

use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Service\SetSynchronizer;
use PHPUnit\Framework\TestCase;

/**
 * Le compteur scalaire `sets` et la collection détaillée décrivent la même chose
 * et doivent rester d'accord, quel que soit le mode où on la change. La référence
 * commune est le nombre de séries **de travail** : l'échauffement ne compte pas.
 */
final class SetSynchronizerTest extends TestCase
{
    private SetSynchronizer $sync;

    protected function setUp(): void
    {
        $this->sync = new SetSynchronizer();
    }

    public function testScalarFollowsDetailedCountExcludingWarmup(): void
    {
        $prescribed = $this->prescribed(4);
        $this->detail($prescribed, [SetType::WARMUP, SetType::NORMAL, SetType::NORMAL, SetType::DROP_SET]);

        $this->sync->syncScalarFromDetailed($prescribed);

        // 4 lignes, mais 3 séries de travail (l'échauffement est hors décompte).
        self::assertSame(3, $prescribed->getSets());
    }

    public function testScalarUntouchedInSimpleMode(): void
    {
        $prescribed = $this->prescribed(4);

        $this->sync->syncScalarFromDetailed($prescribed);

        self::assertSame(4, $prescribed->getSets());
    }

    public function testRaisingScalarAppendsWorkingSetsCopiedFromTheLastOne(): void
    {
        $prescribed = $this->prescribed(4);
        $this->detail($prescribed, [SetType::WARMUP, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL]);
        // Dernière série de travail : 8 reps @ 100 kg.
        $prescribed->getDetailedSets()->last()->setReps(8)->setWeightKg(100.0);

        $created = $this->sync->applyScalarToDetailed($prescribed, 6);

        self::assertCount(2, $created);
        self::assertSame(7, $prescribed->getDetailedSets()->count());
        self::assertSame(6, $prescribed->getWorkingSetCount());
        foreach ($created as $set) {
            self::assertSame(SetType::NORMAL, $set->getSetType());
            self::assertSame(8, $set->getReps());
            self::assertSame(100.0, $set->getWeightKg());
        }
    }

    public function testLoweringScalarRemovesFromTheEndAndNeverTheWarmup(): void
    {
        $prescribed = $this->prescribed(6);
        $this->detail($prescribed, [SetType::WARMUP, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL]);

        $this->sync->applyScalarToDetailed($prescribed, 2);

        self::assertSame(2, $prescribed->getWorkingSetCount());
        self::assertSame(3, $prescribed->getDetailedSets()->count());
        self::assertSame(SetType::WARMUP, $prescribed->getDetailedSets()->first()->getSetType());
    }

    public function testLoweringToZeroKeepsOnlyTheWarmup(): void
    {
        $prescribed = $this->prescribed(3);
        $this->detail($prescribed, [SetType::WARMUP, SetType::NORMAL, SetType::NORMAL]);

        $this->sync->applyScalarToDetailed($prescribed, 0);

        self::assertSame(0, $prescribed->getWorkingSetCount());
        self::assertSame(1, $prescribed->getDetailedSets()->count());
    }

    public function testPositionsStayDenseAfterMutation(): void
    {
        $prescribed = $this->prescribed(4);
        $this->detail($prescribed, [SetType::WARMUP, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL]);

        $this->sync->applyScalarToDetailed($prescribed, 2);

        $positions = array_map(
            static fn (PrescribedSet $set) => $set->getPosition(),
            $prescribed->getDetailedSets()->toArray(),
        );
        sort($positions);
        self::assertSame([0, 1, 2], $positions);
    }

    /**
     * Le scénario qui perdait des séries : détailler, ajouter, revenir au simple.
     * Le compteur doit refléter ce qui a réellement été décrit.
     */
    public function testRoundTripKeepsTheRealCount(): void
    {
        $prescribed = $this->prescribed(4);
        $this->detail($prescribed, [SetType::NORMAL, SetType::NORMAL, SetType::NORMAL, SetType::NORMAL]);

        $this->sync->applyScalarToDetailed($prescribed, 6);
        $this->sync->syncScalarFromDetailed($prescribed);

        self::assertSame(6, $prescribed->getSets());
    }

    private function prescribed(int $sets): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets($sets)
            ->setReps(8)
            ->setWeightKg(100.0);
    }

    /**
     * @param list<SetType> $types
     */
    private function detail(PrescribedExercise $prescribed, array $types): void
    {
        foreach ($types as $position => $type) {
            $prescribed->addDetailedSet(
                (new PrescribedSet())
                    ->setPosition($position)
                    ->setSetType($type)
                    ->setReps(8)
                    ->setWeightKg(100.0)
            );
        }
    }
}
