<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;
use App\Shared\Math\Statistics;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How far under the channel median the video's click-through rate sits.
 *
 * A CTR at or above the median scores 0; at or below a configurable fraction of
 * the median it scores 1, with a linear ramp in between.
 */
#[AutoconfigureTag('app.signal_calculator')]
final class LowCtrSignalCalculator implements SignalCalculatorInterface
{
    public function signal(): Signal
    {
        return Signal::LowCtr;
    }

    public function calculate(VideoMetrics $metrics, ScoringContext $context): ?float
    {
        $median = $context->baseline->medianClickThroughRate;
        if (null === $median || $median <= 0.0) {
            return null;
        }

        $rate = $metrics->series->clickThroughRate($context->windowStart(), $context->windowEnd());
        if (null === $rate) {
            return null;
        }

        $floor = $context->parameters->ctrFloorRatio;
        $ratio = $rate / $median;

        if ($ratio >= 1.0) {
            return 0.0;
        }

        if ($ratio <= $floor) {
            return 1.0;
        }

        return Statistics::clampUnit((1.0 - $ratio) / (1.0 - $floor));
    }
}
