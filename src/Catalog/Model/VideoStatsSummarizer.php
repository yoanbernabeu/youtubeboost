<?php

declare(strict_types=1);

namespace App\Catalog\Model;

use App\Catalog\Entity\Video;

/**
 * Derives the aggregated figures of a video from its daily history.
 */
final class VideoStatsSummarizer
{
    public function summarize(
        Video $video,
        DailyStatSeries $series,
        \DateTimeImmutable $referenceDate,
        int $windowDays,
        int $warmupDays,
    ): VideoStatsSummary {
        $end = $referenceDate->setTime(0, 0);
        $start = $end->modify(\sprintf('-%d days', $windowDays - 1));
        $peakSearchStart = $video->getPublishedAt()->setTime(0, 0)->modify(\sprintf('+%d days', $warmupDays));

        return new VideoStatsSummary(
            $windowDays,
            $video->getViewCount(),
            $series->averageViewsPerDay($start, $end),
            $series->bestRollingAverageViews($windowDays, $peakSearchStart),
            $series->totalImpressions($start, $end),
            $series->clickThroughRate($start, $end),
            $series->averageViewPercentage($video->getPublishedAt(), $end),
            $series->averageViewDuration($start, $end),
            $series->lastDate(),
        );
    }
}
