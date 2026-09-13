<?php

declare(strict_types=1);

namespace App\Relaunch\Tracking;

use App\Relaunch\Model\Comparison;
use App\Relaunch\Model\Verdict;
use App\Relaunch\Model\VerdictThresholds;

/**
 * Turns a before/after comparison into a verdict.
 *
 * Deliberately blunt: the point of the tool is to answer "keep it or revert it",
 * not to produce a nuanced report.
 */
final class VerdictEvaluator
{
    public function evaluate(Comparison $comparison, VerdictThresholds $thresholds): Verdict
    {
        $viewsRatio = $comparison->viewsRatio();
        $ctrRatio = $comparison->clickThroughRateRatio();

        if (null === $viewsRatio) {
            // Without a usable "before", nothing can be claimed either way.
            return Verdict::Neutral;
        }

        if ($viewsRatio < $thresholds->neutralFloorRatio) {
            return Verdict::Negative;
        }

        if (null !== $ctrRatio && $ctrRatio < $thresholds->negativeCtrRatio) {
            return Verdict::Negative;
        }

        if ($viewsRatio >= $thresholds->successViewsRatio && (null === $ctrRatio || $ctrRatio >= 1.0)) {
            return Verdict::Successful;
        }

        return Verdict::Neutral;
    }
}
