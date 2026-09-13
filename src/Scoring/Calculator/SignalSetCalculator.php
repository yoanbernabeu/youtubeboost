<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\SignalSet;
use App\Scoring\Model\VideoMetrics;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every registered signal calculator and collects their answers.
 */
final class SignalSetCalculator
{
    /**
     * @param iterable<SignalCalculatorInterface> $calculators
     */
    public function __construct(
        #[AutowireIterator('app.signal_calculator')]
        private readonly iterable $calculators,
    ) {
    }

    public function calculate(VideoMetrics $metrics, ScoringContext $context): SignalSet
    {
        $set = SignalSet::empty();
        foreach ($this->calculators as $calculator) {
            $set = $set->with($calculator->signal(), $calculator->calculate($metrics, $context));
        }

        return $set;
    }
}
