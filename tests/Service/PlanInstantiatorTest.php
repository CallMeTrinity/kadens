<?php

namespace App\Tests\Service;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use App\Service\PlanInstantiator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PlanInstantiatorTest extends TestCase
{
    private function makeTemplate(): PlanTemplate
    {
        $template = (new PlanTemplate())->setTitle('Plan 5k')->setDurationWeeks(2);

        // Semaine 1, mercredi (jour ISO 3).
        $template->addPlanItem(
            (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout((new Workout())->setTitle('Fractionné')->setSlug('fractionne'))
        );
        // Semaine 2, lundi (jour ISO 1).
        $template->addPlanItem(
            (new PlanItem())->setWeekNumber(2)->setDayOfWeek(1)->setWorkout((new Workout())->setTitle('Sortie longue')->setSlug('sortie-longue'))
        );

        return $template;
    }

    private function makeInstantiator(): PlanInstantiator
    {
        // L'EntityManager est un stub : on ne teste que la projection des dates,
        // pas la persistance. persist/flush sont des no-ops.
        $em = $this->createStub(EntityManagerInterface::class);

        return new PlanInstantiator($em);
    }

    public function testMapsWeekAndDayToRealDatesFromMidWeekStart(): void
    {
        // 2026-01-07 est un mercredi : l'ancre est le lundi de sa semaine ISO
        // (2026-01-05).
        $created = $this->makeInstantiator()->instantiate(
            $this->makeTemplate(),
            new User(),
            new \DateTimeImmutable('2026-01-07'),
        );

        self::assertCount(2, $created);
        // Semaine 1 mercredi -> 2026-01-07 (mercredi).
        self::assertSame('2026-01-07', $created[0]->getScheduledDate()->format('Y-m-d'));
        // Semaine 2 lundi -> 2026-01-12 (lundi).
        self::assertSame('2026-01-12', $created[1]->getScheduledDate()->format('Y-m-d'));
    }

    public function testAnchorsToIsoMondayEvenFromSundayStart(): void
    {
        // 2026-01-11 est un dimanche (jour ISO 7) : l'ancre reste le lundi de la
        // même semaine ISO (2026-01-05), pas la semaine suivante.
        $created = $this->makeInstantiator()->instantiate(
            $this->makeTemplate(),
            new User(),
            new \DateTimeImmutable('2026-01-11'),
        );

        self::assertSame('2026-01-07', $created[0]->getScheduledDate()->format('Y-m-d'));
        self::assertSame('2026-01-12', $created[1]->getScheduledDate()->format('Y-m-d'));
    }

    public function testSetsOwnerStatusAndSourceTemplate(): void
    {
        $owner = new User();
        $template = $this->makeTemplate();

        $created = $this->makeInstantiator()->instantiate($template, $owner, new \DateTimeImmutable('2026-01-05'));

        foreach ($created as $scheduled) {
            self::assertSame($owner, $scheduled->getOwner());
            self::assertSame($template, $scheduled->getSourcePlanTemplate());
            self::assertSame(ScheduledStatus::PLANNED, $scheduled->getStatus());
            self::assertNotNull($scheduled->getWorkout());
        }
    }
}
