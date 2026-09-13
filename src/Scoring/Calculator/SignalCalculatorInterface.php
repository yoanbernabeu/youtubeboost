<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;

/**
 * Computes one normalised signal of the relaunch score.
 *
 * Returning null means "no data", which neutralises the signal instead of
 * counting it as zero.
 */
interface SignalCalculatorInterface
{
    public function signal(): Signal;

    public function calculate(VideoMetrics $metrics, ScoringContext $context): ?float;
}
