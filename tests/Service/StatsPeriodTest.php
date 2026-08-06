<?php

namespace App\Tests\Service;

use App\Enum\StatsRange;
use App\Service\StatsPeriod;
use PHPUnit\Framework\TestCase;

/**
 * La résolution de la fenêtre de statistiques.
 *
 * Ce que ces tests protègent réellement : la fenêtre est un paramètre d'URL,
 * donc une chaîne écrite par n'importe qui. Elle doit toujours produire des
 * bornes utilisables — un lien périmé affiche des statistiques, jamais une 404 —
 * et ces bornes doivent être des JOURS PLEINS, parce que `ScheduledWorkout`
 * porte une date et non un instant.
 */
final class StatsPeriodTest extends TestCase
{
    private const NOW = '2026-08-06';

    public function testDefaultsToFourWeeksWhenNothingIsAsked(): void
    {
        foreach ([null, '', '   ', 'lundi', '4W', '2026-13', 'all-time'] as $raw) {
            $period = StatsPeriod::resolve($raw, $this->now());

            self::assertSame(
                StatsRange::FOUR_WEEKS,
                $period->range,
                sprintf('« %s » doit retomber sur la fenêtre par défaut, pas lever.', var_export($raw, true)),
            );
        }
    }

    public function testFourWeeksCoversTwentyEightFullDaysEndingToday(): void
    {
        $period = StatsPeriod::resolve('4w', $this->now());

        // 28 jours bornes incluses : du 10/07 au 06/08. Une fenêtre « 4
        // semaines » qui en couvrirait 29 ferait basculer une séance du bord.
        self::assertSame('2026-07-10 00:00:00', $period->start?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-06 23:59:59', $period->end->format('Y-m-d H:i:s'));
        self::assertSame(28, $period->dayCount());
    }

    public function testAllTimeHasNoLowerBoundAndBorrowsItsSpanFromTheData(): void
    {
        $period = StatsPeriod::resolve('all', $this->now());

        self::assertNull($period->start);
        self::assertFalse($period->isBounded());

        // Sans historique, la durée vaut un jour : c'est un dénominateur, il ne
        // peut pas valoir zéro.
        self::assertSame(1, $period->dayCount());

        // Avec historique, elle vaut celle de l'historique réel — pas celle du
        // compte, qui pourrait avoir été créé deux ans avant la première séance.
        self::assertSame(31, $period->dayCount(new \DateTimeImmutable('2026-07-07')));
    }

    public function testAMonthIsRecognisedByItsShapeAndEndsOnItsLastDay(): void
    {
        $period = StatsPeriod::resolve('2026-02', $this->now());

        self::assertSame(StatsRange::MONTH, $period->range);
        self::assertSame('2026-02-01 00:00:00', $period->start?->format('Y-m-d H:i:s'));
        // 2026 n'est pas bissextile : la borne haute doit suivre le mois, pas
        // une durée fixe.
        self::assertSame('2026-02-28 23:59:59', $period->end->format('Y-m-d H:i:s'));
        self::assertSame(28, $period->dayCount());
        self::assertSame('février 2026', $period->label);
        self::assertSame('2026-02', $period->monthKey());
    }

    public function testTheQueryValueRoundTrips(): void
    {
        // Ce qui sort doit pouvoir rentrer : c'est ce qui permet au sélecteur
        // de se marquer actif, et à un lien de se partager.
        foreach (['4w', '6m', 'all', '2026-02'] as $raw) {
            self::assertSame($raw, StatsPeriod::resolve($raw, $this->now())->queryValue);
        }
    }

    public function testOnlyAMonthCarriesAMonthKey(): void
    {
        foreach (['4w', '6m', 'all'] as $raw) {
            self::assertNull(StatsPeriod::resolve($raw, $this->now())->monthKey());
        }
    }

    public function testSixMonthsEndsTodayAndStartsSixMonthsBack(): void
    {
        $period = StatsPeriod::resolve('6m', $this->now());

        self::assertSame('2026-02-07 00:00:00', $period->start?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-06 23:59:59', $period->end->format('Y-m-d H:i:s'));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW.' 14:32:11');
    }
}
