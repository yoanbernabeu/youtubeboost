<?php

declare(strict_types=1);

namespace App\Catalog\Chart;

use App\Catalog\Model\DailyStatSeries;

/**
 * Turns a daily history into something a chart can draw without shipping
 * thousands of points to the browser.
 *
 * A video published four years ago has around 1 500 days of data; beyond a
 * threshold the series is aggregated by week, which is also easier to read.
 */
final class ViewsSeriesBuilder
{
    /** Above this many days, the series is aggregated by week. */
    public const int DAILY_POINT_LIMIT = 400;

    /** Window of the moving average overlay. */
    public const int TREND_WINDOW = 28;

    public function build(DailyStatSeries $series, ?\DateTimeImmutable $until = null): ViewsSeries
    {
        $first = $series->firstDate();
        $last = $until ?? $series->lastDate();
        if (null === $first || null === $last || $first > $last) {
            return new ViewsSeries([], [], [], false);
        }

        $days = 1 + (int) $first->setTime(0, 0)->diff($last->setTime(0, 0))->format('%a');
        $weekly = $days > self::DAILY_POINT_LIMIT;

        $dailyViews = [];
        $day = $first->setTime(0, 0);
        for ($i = 0; $i < $days; ++$i) {
            $dailyViews[$day->format('Y-m-d')] = $series->viewsBetween($day, $day);
            $day = $day->modify('+1 day');
        }

        $trend = $this->movingAverage(array_values($dailyViews), self::TREND_WINDOW);

        if (!$weekly) {
            return new ViewsSeries(array_keys($dailyViews), array_values($dailyViews), $trend, false);
        }

        return $this->aggregateByWeek($dailyViews, $trend);
    }

    /**
     * @param list<int> $values
     *
     * @return list<float|null>
     */
    private function movingAverage(array $values, int $window): array
    {
        $averages = [];
        $sum = 0;
        foreach ($values as $index => $value) {
            $sum += $value;
            if ($index >= $window) {
                $sum -= $values[$index - $window];
            }

            $averages[] = $index + 1 >= $window ? round($sum / $window, 2) : null;
        }

        return $averages;
    }

    /**
     * @param array<string, int> $dailyViews
     * @param list<float|null>   $trend
     */
    private function aggregateByWeek(array $dailyViews, array $trend): ViewsSeries
    {
        $labels = [];
        $views = [];
        $weeklyTrend = [];

        $bucketViews = 0;
        $bucketTrend = [];
        $bucketLabel = null;
        $index = 0;

        foreach ($dailyViews as $date => $value) {
            $bucketLabel ??= $date;
            $bucketViews += $value;
            if (null !== $trend[$index]) {
                $bucketTrend[] = $trend[$index];
            }

            if (6 === $index % 7) {
                $labels[] = $bucketLabel;
                $views[] = $bucketViews;
                $weeklyTrend[] = [] === $bucketTrend ? null : round(array_sum($bucketTrend) / \count($bucketTrend), 2);
                $bucketViews = 0;
                $bucketTrend = [];
                $bucketLabel = null;
            }

            ++$index;
        }

        if (null !== $bucketLabel) {
            $labels[] = $bucketLabel;
            $views[] = $bucketViews;
            $weeklyTrend[] = [] === $bucketTrend ? null : round(array_sum($bucketTrend) / \count($bucketTrend), 2);
        }

        return new ViewsSeries($labels, $views, $weeklyTrend, true);
    }
}
