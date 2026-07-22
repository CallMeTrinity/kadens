<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Service\PlanFlattener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanFlattenerTest extends TestCase
{
    private PlanFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new PlanFlattener();
    }

    #[DataProvider('summaryCases')]
    public function testSummaryFormatting(PrescribedExercise $prescribed, string $expected): void
    {
        $exercise = (new Exercise())->setName('Test')->setActivity(ActivityType::GYM);
        $prescribed->setExercise($exercise)->setPosition(0);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);

        $workout = (new Workout())->setTitle('Séance')->setSlug('seance');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout);

        self::assertSame($expected, $flat['blocks'][0]['exercises'][0]['summary']);
    }

    public function testFlattenPlanTemplateProducesDenseGrid(): void
    {
        $workout = (new Workout())->setTitle('Sortie longue')->setSlug('sortie-longue');

        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setNotes('en Z2');
        $item->setWorkout($workout);

        $template = (new PlanTemplate())->setTitle('Plan 5k')->setDurationWeeks(2);
        $template->addPlanItem($item);

        $flat = $this->flattener->flattenPlanTemplate($template);

        // Grille dense : autant de semaines que déclarées, 7 jours chacune.
        self::assertCount(2, $flat['weeks']);
        self::assertCount(7, $flat['weeks'][0]['days']);
        self::assertSame(1, $flat['weeks'][0]['weekNumber']);
        self::assertSame(4, $flat['weeks'][0]['days'][3]['dayOfWeek']);

        // La séance est bien placée en semaine 1, jour 3 (index 2).
        $cell = $flat['weeks'][0]['days'][2];
        self::assertSame(3, $cell['dayOfWeek']);
        self::assertCount(1, $cell['items']);
        self::assertSame('Sortie longue', $cell['items'][0]['workout']['workout']->getTitle());
        self::assertSame('en Z2', $cell['items'][0]['item']->getNotes());

        // Les autres cases sont vides.
        self::assertSame([], $flat['weeks'][0]['days'][0]['items']);
        self::assertSame([], $flat['weeks'][1]['days'][2]['items']);
    }

    public static function summaryCases(): iterable
    {
        yield 'sets_reps avec charge' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(4)->setReps(8)->setWeightKg(60.0),
            '4 × 8 @ 60 kg',
        ];

        yield 'sets_time' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::SETS_TIME)
                ->setSets(3)->setDurationSeconds(45),
            '3 × 0:45',
        ];

        yield 'amrap avec cible' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::AMRAP)
                ->setDurationSeconds(720)->setTargetReps(100),
            'AMRAP 12:00 · cible 100 reps',
        ];

        yield 'for_time avec cap' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::FOR_TIME)
                ->setTargetReps(30)->setCapSeconds(300),
            '30 reps for time · cap 5:00',
        ];

        yield 'distance_pace en km' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                ->setDistanceMeters(5000)->setPaceSecondsPerKm(300),
            '5 km @ 5:00/km',
        ];

        yield 'duration avec zone' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::DURATION)
                ->setDurationSeconds(2400)->setIntensityZone('Z2'),
            '40:00 · Z2',
        ];
    }
}
