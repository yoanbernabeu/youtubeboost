<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Chart;

use App\Catalog\Chart\ViewsSeries;
use App\Catalog\Chart\ViewsSeriesBuilder;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Model\DailyStatSeries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewsSeriesBuilder::class)]
#[CoversClass(ViewsSeries::class)]
final class ViewsSeriesBuilderTest extends TestCase
{
    public function testAnEmptyHistoryGivesAnEmptySeries(): void
    {
        $series = new ViewsSeriesBuilder()->build(DailyStatSeries::fromPoints([]));

        self::assertTrue($series->isEmpty());
        self::assertFalse($series->isWeekly);
    }

    public function testAShortHistoryStaysDaily(): void
    {
        $series = new ViewsSeriesBuilder()->build(self::history('2026-01-01', 30, 10));

        self::assertFalse($series->isWeekly);
        self::assertCount(30, $series->labels);
        self::assertSame('2026-01-01', $series->labels[0]);
        self::assertSame('2026-01-30', $series->labels[29]);
        self::assertSame(array_fill(0, 30, 10), $series->views);
        self::assertSame('video.chart.views_per_day', $series->viewsLabel()->getMessage());
    }

    public function testMissingDaysAreFilledWithZero(): void
    {
        $series = new ViewsSeriesBuilder()->build(DailyStatSeries::fromPoints([
            new DailyPoint(new \DateTimeImmutable('2026-01-01'), 10, null, null, null, null, 0),
            new DailyPoint(new \DateTimeImmutable('2026-01-04'), 40, null, null, null, null, 0),
        ]));

        self::assertSame(['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04'], $series->labels);
        self::assertSame([10, 0, 0, 40], $series->views);
    }

    public function testTheMovingAverageOnlyStartsOnceItsWindowIsFull(): void
    {
        $series = new ViewsSeriesBuilder()->build(self::history('2026-01-01', 30, 10));

        self::assertNull($series->trend[26]);
        self::assertSame(10.0, $series->trend[27]);
        self::assertSame('video.chart.trend', $series->trendLabel()->getMessage());
    }

    public function testTheMovingAverageFollowsTheData(): void
    {
        $points = [];
        $day = new \DateTimeImmutable('2026-01-01');
        foreach ([...array_fill(0, 28, 100), ...array_fill(0, 28, 0)] as $views) {
            $points[] = new DailyPoint($day, $views, null, null, null, null, 0);
            $day = $day->modify('+1 day');
        }

        $series = new ViewsSeriesBuilder()->build(DailyStatSeries::fromPoints($points));

        self::assertSame(100.0, $series->trend[27]);
        self::assertSame(0.0, $series->trend[55]);
    }

    public function testALongHistoryIsAggregatedByWeek(): void
    {
        $series = new ViewsSeriesBuilder()->build(self::history('2022-01-01', 700, 10));

        self::assertTrue($series->isWeekly);
        self::assertCount(100, $series->labels);
        self::assertSame(70, $series->views[0], 'Seven days at ten views each.');
        self::assertSame('video.chart.views_per_week', $series->viewsLabel()->getMessage());
    }

    public function testAnIncompleteLastWeekIsStillIncluded(): void
    {
        $series = new ViewsSeriesBuilder()->build(self::history('2022-01-01', 704, 10));

        self::assertCount(101, $series->labels);
        self::assertSame(40, $series->views[100], 'Four leftover days.');
    }

    public function testTheSeriesStopsAtTheRequestedBoundary(): void
    {
        $series = new ViewsSeriesBuilder()->build(self::history('2026-01-01', 30, 10), new \DateTimeImmutable('2026-01-10'));

        self::assertCount(10, $series->labels);
    }

    public function testABoundaryBeforeTheFirstDayGivesAnEmptySeries(): void
    {
        $series = new ViewsSeriesBuilder()->build(self::history('2026-01-01', 30, 10), new \DateTimeImmutable('2025-12-01'));

        self::assertTrue($series->isEmpty());
    }

    private static function history(string $from, int $days, int $viewsPerDay): DailyStatSeries
    {
        $points = [];
        $day = new \DateTimeImmutable($from);
        for ($i = 0; $i < $days; ++$i) {
            $points[] = new DailyPoint($day, $viewsPerDay, null, null, null, null, 0);
            $day = $day->modify('+1 day');
        }

        return DailyStatSeries::fromPoints($points);
    }
}
