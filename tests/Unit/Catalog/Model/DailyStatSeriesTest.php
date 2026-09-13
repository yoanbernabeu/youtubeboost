<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Model;

use App\Catalog\Model\DailyPoint;
use App\Catalog\Model\DailyStatSeries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DailyStatSeries::class)]
#[CoversClass(DailyPoint::class)]
final class DailyStatSeriesTest extends TestCase
{
    public function testAnEmptySeriesAnswersNullEverywhere(): void
    {
        $series = DailyStatSeries::fromPoints([]);

        self::assertTrue($series->isEmpty());
        self::assertNull($series->firstDate());
        self::assertNull($series->lastDate());
        self::assertNull($series->averageViewsPerDay(self::date('2026-01-01'), self::date('2026-01-28')));
        self::assertNull($series->bestRollingAverageViews(28, self::date('2026-01-01')));
        self::assertNull($series->totalImpressions(self::date('2026-01-01'), self::date('2026-01-28')));
        self::assertNull($series->clickThroughRate(self::date('2026-01-01'), self::date('2026-01-28')));
        self::assertNull($series->averageViewPercentage(self::date('2026-01-01'), self::date('2026-01-28')));
        self::assertSame(0, $series->totalViews());
    }

    public function testItSortsPointsByDateAndExposesItsBounds(): void
    {
        $series = DailyStatSeries::fromPoints([
            self::point('2026-01-03', 30),
            self::point('2026-01-01', 10),
            self::point('2026-01-02', 20),
        ]);

        self::assertSame('2026-01-01', $series->firstDate()?->format('Y-m-d'));
        self::assertSame('2026-01-03', $series->lastDate()?->format('Y-m-d'));
        self::assertSame(60, $series->totalViews());
    }

    public function testAverageViewsPerDayDividesByTheWholeWindowIncludingDaysWithoutData(): void
    {
        $series = DailyStatSeries::fromPoints([
            self::point('2026-01-01', 10),
            self::point('2026-01-02', 20),
        ]);

        // 30 views spread over the 3 requested days.
        self::assertSame(10.0, $series->averageViewsPerDay(self::date('2026-01-01'), self::date('2026-01-03')));
    }

    public function testAverageViewsPerDayIgnoresPointsOutsideTheWindow(): void
    {
        $series = DailyStatSeries::fromPoints([
            self::point('2025-12-31', 1000),
            self::point('2026-01-01', 10),
            self::point('2026-01-02', 30),
            self::point('2026-01-03', 5000),
        ]);

        self::assertSame(20.0, $series->averageViewsPerDay(self::date('2026-01-01'), self::date('2026-01-02')));
    }

    public function testAverageViewsPerDayIsNullWhenTheWindowHoldsNoData(): void
    {
        $series = DailyStatSeries::fromPoints([self::point('2026-01-01', 10)]);

        self::assertNull($series->averageViewsPerDay(self::date('2026-02-01'), self::date('2026-02-28')));
    }

    public function testBestRollingAverageFindsTheStrongestWindow(): void
    {
        $points = [];
        // 10 views/day for 10 days, then 50 views/day for 5 days, then 1 view/day for 10 days.
        $day = self::date('2026-01-01');
        foreach ([...array_fill(0, 10, 10), ...array_fill(0, 5, 50), ...array_fill(0, 10, 1)] as $views) {
            $points[] = new DailyPoint($day, $views, null, null, null, null, 0);
            $day = $day->modify('+1 day');
        }
        $series = DailyStatSeries::fromPoints($points);

        // Best 5-day window is the 50-views plateau.
        self::assertSame(50.0, $series->bestRollingAverageViews(5, self::date('2026-01-01')));
    }

    public function testBestRollingAverageSkipsDaysBeforeTheWarmupBoundary(): void
    {
        $points = [];
        $day = self::date('2026-01-01');
        foreach ([...array_fill(0, 5, 100), ...array_fill(0, 5, 10)] as $views) {
            $points[] = new DailyPoint($day, $views, null, null, null, null, 0);
            $day = $day->modify('+1 day');
        }
        $series = DailyStatSeries::fromPoints($points);

        self::assertSame(10.0, $series->bestRollingAverageViews(5, self::date('2026-01-06')));
    }

    public function testBestRollingAverageIsNullWhenTheSeriesIsShorterThanTheWindow(): void
    {
        $series = DailyStatSeries::fromPoints([
            self::point('2026-01-01', 10),
            self::point('2026-01-02', 10),
        ]);

        self::assertNull($series->bestRollingAverageViews(28, self::date('2026-01-01')));
    }

    public function testBestRollingAverageTreatsMissingDaysAsZeroViews(): void
    {
        $series = DailyStatSeries::fromPoints([
            self::point('2026-01-01', 100),
            self::point('2026-01-04', 100),
        ]);

        // 4 days spanned, 200 views, best 4-day window is the whole span.
        self::assertSame(50.0, $series->bestRollingAverageViews(4, self::date('2026-01-01')));
    }

    public function testTotalImpressionsSumsOnlyKnownValues(): void
    {
        $series = DailyStatSeries::fromPoints([
            new DailyPoint(self::date('2026-01-01'), 10, 1000, 2.0, null, null, 0),
            new DailyPoint(self::date('2026-01-02'), 10, null, null, null, null, 0),
            new DailyPoint(self::date('2026-01-03'), 10, 500, 4.0, null, null, 0),
        ]);

        self::assertSame(1500, $series->totalImpressions(self::date('2026-01-01'), self::date('2026-01-03')));
    }

    public function testTotalImpressionsIsNullWhenNoDayCarriesImpressionData(): void
    {
        $series = DailyStatSeries::fromPoints([self::point('2026-01-01', 10)]);

        self::assertNull($series->totalImpressions(self::date('2026-01-01'), self::date('2026-01-03')));
    }

    public function testClickThroughRateIsWeightedByImpressions(): void
    {
        $series = DailyStatSeries::fromPoints([
            new DailyPoint(self::date('2026-01-01'), 10, 1000, 2.0, null, null, 0),
            new DailyPoint(self::date('2026-01-02'), 10, 3000, 6.0, null, null, 0),
        ]);

        // (1000*2 + 3000*6) / 4000 = 5.0
        self::assertSame(5.0, $series->clickThroughRate(self::date('2026-01-01'), self::date('2026-01-02')));
    }

    public function testAverageViewPercentageIsWeightedByViews(): void
    {
        $series = DailyStatSeries::fromPoints([
            new DailyPoint(self::date('2026-01-01'), 100, null, null, 50.0, null, 0),
            new DailyPoint(self::date('2026-01-02'), 300, null, null, 30.0, null, 0),
        ]);

        // (100*50 + 300*30) / 400 = 35.0
        self::assertSame(35.0, $series->averageViewPercentage(self::date('2026-01-01'), self::date('2026-01-02')));
    }

    public function testAverageViewDurationIsWeightedByViews(): void
    {
        $series = DailyStatSeries::fromPoints([
            new DailyPoint(self::date('2026-01-01'), 100, null, null, null, 120, 0),
            new DailyPoint(self::date('2026-01-02'), 100, null, null, null, 60, 0),
        ]);

        self::assertSame(90.0, $series->averageViewDuration(self::date('2026-01-01'), self::date('2026-01-02')));
    }

    public function testWindowBoundsAreInclusive(): void
    {
        $series = DailyStatSeries::fromPoints([
            self::point('2026-01-01', 10),
            self::point('2026-01-02', 10),
            self::point('2026-01-03', 10),
        ]);

        self::assertSame(30, $series->viewsBetween(self::date('2026-01-01'), self::date('2026-01-03')));
    }

    public function testTimeComponentsOfTheBoundsAreIgnored(): void
    {
        $series = DailyStatSeries::fromPoints([self::point('2026-01-02', 10)]);

        $from = new \DateTimeImmutable('2026-01-02 23:30:00');
        $to = new \DateTimeImmutable('2026-01-02 01:00:00');

        self::assertSame(10, $series->viewsBetween($from, $to));
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value . ' 00:00:00');
    }

    private static function point(string $date, int $views): DailyPoint
    {
        return new DailyPoint(self::date($date), $views, null, null, null, null, 0);
    }
}
