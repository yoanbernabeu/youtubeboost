<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;
use App\Shared\Math\Statistics;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Whether YouTube still puts the video in front of people.
 *
 * Changing a thumbnail can only pay off on a video that is still being served,
 * so this acts as a gate rather than a reward: it is capped at the channel median.
 */
#[AutoconfigureTag('app.signal_calculator')]
final class ImpressionsSignalCalculator implements SignalCalculatorInterface
{
    public function signal(): Signal
    {
        return Signal::Impressions;
    }

    public function calculate(VideoMetrics $metrics, ScoringContext $context): ?float
    {
        $median = $context->baseline->medianImpressions;
        if (null === $median || $median <= 0.0) {
            return null;
        }

        $impressions = $metrics->series->totalImpressions($context->windowStart(), $context->windowEnd());
        if (null === $impressions) {
            return null;
        }

        return Statistics::clampUnit($impressions / $median);
    }
}
