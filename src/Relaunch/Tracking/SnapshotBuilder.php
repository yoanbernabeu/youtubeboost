<?php

declare(strict_types=1);

namespace App\Relaunch\Tracking;

use App\Catalog\Model\DailyStatSeries;
use App\Relaunch\Model\PerformanceSnapshot;

/**
 * Reduces a window of the daily history to the few averages a verdict needs.
 */
final class SnapshotBuilder
{
    public function build(DailyStatSeries $series, \DateTimeImmutable $from, \DateTimeImmutable $to): PerformanceSnapshot
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);
        if ($start > $end) {
            return PerformanceSnapshot::empty();
        }

        $days = 1 + (int) $start->diff($end)->format('%a');
        $impressions = $series->totalImpressions($start, $end);

        return new PerformanceSnapshot(
            $days,
            $series->viewsBetween($start, $end) / $days,
            null === $impressions ? null : $impressions / $days,
            $series->clickThroughRate($start, $end),
            $series->averageViewDuration($start, $end),
            $series->averageViewPercentage($start, $end),
        );
    }
}
