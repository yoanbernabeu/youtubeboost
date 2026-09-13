<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;
use App\Shared\Math\Statistics;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How far the video has fallen from its own best days.
 *
 * The launch spike is excluded from the yardstick: almost every video beats its
 * later self during the first weeks, which would make every video look dying.
 */
#[AutoconfigureTag('app.signal_calculator')]
final class DeclineSignalCalculator implements SignalCalculatorInterface
{
    public function signal(): Signal
    {
        return Signal::Decline;
    }

    public function calculate(VideoMetrics $metrics, ScoringContext $context): ?float
    {
        $current = $metrics->series->averageViewsPerDay($context->windowStart(), $context->windowEnd());
        if (null === $current) {
            return null;
        }

        $peakSearchStart = $metrics->video->getPublishedAt()
            ->setTime(0, 0)
            ->modify(\sprintf('+%d days', $context->parameters->warmupDays));

        $best = $metrics->series->bestRollingAverageViews($context->parameters->windowDays, $peakSearchStart);
        if (null === $best || $best <= 0.0) {
            return null;
        }

        return Statistics::clampUnit(1.0 - $current / $best);
    }
}
