<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;
use App\Shared\Math\Statistics;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Whether the video already proved it interests people.
 *
 * Two halves: audience size, on a logarithmic scale so a 10x gap does not crush
 * everything else, and retention relative to the rest of the channel.
 */
#[AutoconfigureTag('app.signal_calculator')]
final class PotentialSignalCalculator implements SignalCalculatorInterface
{
    public function signal(): Signal
    {
        return Signal::Potential;
    }

    public function calculate(VideoMetrics $metrics, ScoringContext $context): ?float
    {
        $components = array_filter(
            [$this->audienceComponent($metrics, $context), $this->retentionComponent($metrics, $context)],
            static fn (?float $value): bool => null !== $value,
        );

        if ([] === $components) {
            return null;
        }

        return Statistics::clampUnit(array_sum($components) / \count($components));
    }

    private function audienceComponent(VideoMetrics $metrics, ScoringContext $context): ?float
    {
        $reference = $context->baseline->referenceViewCount;
        if (null === $reference || $reference <= 0.0) {
            return null;
        }

        $scale = log10(1.0 + $reference);
        if ($scale <= 0.0) {
            return null;
        }

        return Statistics::clampUnit(log10(1.0 + $metrics->video->getViewCount()) / $scale);
    }

    private function retentionComponent(VideoMetrics $metrics, ScoringContext $context): ?float
    {
        $median = $context->baseline->medianAverageViewPercentage;
        if (null === $median || $median <= 0.0) {
            return null;
        }

        $retention = $metrics->series->averageViewPercentage(
            $metrics->video->getPublishedAt(),
            $context->windowEnd(),
        );
        if (null === $retention) {
            return null;
        }

        return Statistics::clampUnit($retention / $median / $context->parameters->retentionTargetRatio);
    }
}
