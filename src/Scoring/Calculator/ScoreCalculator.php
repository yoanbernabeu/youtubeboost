<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\Score;
use App\Scoring\Model\Signal;
use App\Scoring\Model\SignalSet;
use App\Scoring\Model\Weights;

/**
 * Turns normalised signals into a 0-100 relaunch score.
 *
 * score = 100 x sum(weight_i x signal_i) / sum(weight_i), restricted to the
 * signals that actually have data.
 */
final class ScoreCalculator
{
    public function calculate(SignalSet $signals, Weights $weights): Score
    {
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach (Signal::cases() as $signal) {
            $value = $signals->get($signal);
            if (null === $value) {
                continue;
            }

            $weight = $weights->get($signal);
            $weighted += $weight * $value;
            $totalWeight += $weight;
        }

        $value = $totalWeight > 0.0 ? (int) round(100 * $weighted / $totalWeight) : 0;

        return new Score(max(0, min(100, $value)), $signals);
    }
}
