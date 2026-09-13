<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\Score;
use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\ScoringResult;
use App\Scoring\Model\SignalSet;
use App\Scoring\Model\VideoMetrics;
use App\Scoring\Model\Weights;

/**
 * Entry point of the scoring module: eligibility first, signals second.
 */
final class RelaunchScorer
{
    public function __construct(
        private readonly SignalSetCalculator $signals,
        private readonly ScoreCalculator $calculator,
        private readonly EligibilityChecker $eligibilityChecker,
    ) {
    }

    public function score(
        VideoMetrics $metrics,
        ScoringContext $context,
        ?\DateTimeImmutable $lastRelaunchAt,
        Weights $weights,
    ): ScoringResult {
        $eligibility = $this->eligibilityChecker->check($metrics->video, $context, $lastRelaunchAt);
        if (!$eligibility->isEligible) {
            return new ScoringResult(new Score(0, SignalSet::empty()), $eligibility);
        }

        $signals = $this->signals->calculate($metrics, $context);

        return new ScoringResult($this->calculator->calculate($signals, $weights), $eligibility);
    }
}
