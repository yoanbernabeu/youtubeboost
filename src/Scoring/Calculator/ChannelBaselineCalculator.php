<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ChannelBaseline;
use App\Scoring\Model\ScoringParameters;
use App\Scoring\Model\VideoMetrics;
use App\Shared\Math\Statistics;

/**
 * Derives the channel-wide yardsticks every video is compared against.
 *
 * Only regular, visible videos take part: Shorts and private videos would skew
 * the medians a long-form catalogue is judged by.
 */
final class ChannelBaselineCalculator
{
    /** A video this popular is treated as the channel's ceiling. */
    private const float REFERENCE_VIEWS_PERCENTILE = 0.9;

    /**
     * @param iterable<VideoMetrics> $catalogue
     */
    public function calculate(iterable $catalogue, ScoringParameters $parameters, \DateTimeImmutable $referenceDate): ChannelBaseline
    {
        $windowStart = $parameters->windowStart($referenceDate);
        $windowEnd = $referenceDate->setTime(0, 0);

        $rates = [];
        $impressions = [];
        $retentions = [];
        $viewCounts = [];

        foreach ($catalogue as $metrics) {
            if (!$metrics->video->isRelaunchable()) {
                continue;
            }

            $rate = $metrics->series->clickThroughRate($windowStart, $windowEnd);
            if (null !== $rate) {
                $rates[] = $rate;
            }

            $total = $metrics->series->totalImpressions($windowStart, $windowEnd);
            if (null !== $total) {
                $impressions[] = (float) $total;
            }

            $retention = $metrics->series->averageViewPercentage($metrics->video->getPublishedAt(), $windowEnd);
            if (null !== $retention) {
                $retentions[] = $retention;
            }

            if ($metrics->video->getViewCount() > 0) {
                $viewCounts[] = (float) $metrics->video->getViewCount();
            }
        }

        return new ChannelBaseline(
            Statistics::median($rates),
            Statistics::median($impressions),
            Statistics::median($retentions),
            Statistics::percentile($viewCounts, self::REFERENCE_VIEWS_PERCENTILE),
        );
    }
}
