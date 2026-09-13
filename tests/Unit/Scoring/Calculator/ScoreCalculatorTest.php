<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring\Calculator;

use App\Scoring\Calculator\ScoreCalculator;
use App\Scoring\Model\Signal;
use App\Scoring\Model\SignalSet;
use App\Scoring\Model\Weights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScoreCalculator::class)]
final class ScoreCalculatorTest extends TestCase
{
    private ScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ScoreCalculator();
    }

    public function testAllSignalsAtTheirMaximumGiveOneHundred(): void
    {
        $signals = SignalSet::fromArray([
            'decline' => 1.0,
            'low_ctr' => 1.0,
            'impressions' => 1.0,
            'potential' => 1.0,
        ]);

        self::assertSame(100, $this->calculator->calculate($signals, Weights::defaults())->value);
    }

    public function testAllSignalsAtZeroGiveZero(): void
    {
        $signals = SignalSet::fromArray([
            'decline' => 0.0,
            'low_ctr' => 0.0,
            'impressions' => 0.0,
            'potential' => 0.0,
        ]);

        self::assertSame(0, $this->calculator->calculate($signals, Weights::defaults())->value);
    }

    public function testItIsTheWeightedAverageOfTheSignals(): void
    {
        $signals = SignalSet::fromArray([
            'decline' => 1.0,
            'low_ctr' => 0.5,
            'impressions' => 0.0,
            'potential' => 0.2,
        ]);

        // (30*1 + 30*0.5 + 15*0 + 25*0.2) / 100 = 0.50 -> 50
        self::assertSame(50, $this->calculator->calculate($signals, Weights::defaults())->value);
    }

    public function testMissingSignalsAreExcludedFromBothSides(): void
    {
        $signals = SignalSet::fromArray([
            'decline' => 1.0,
            'low_ctr' => null,
            'impressions' => null,
            'potential' => 0.0,
        ]);

        // (30*1 + 25*0) / (30 + 25) = 0.5454... -> 55
        self::assertSame(55, $this->calculator->calculate($signals, Weights::defaults())->value);
    }

    public function testAnEmptySignalSetScoresZero(): void
    {
        $score = $this->calculator->calculate(SignalSet::empty(), Weights::defaults());

        self::assertSame(0, $score->value);
        self::assertFalse($score->isReliable());
    }

    public function testAScoreIsReliableAsSoonAsTheDeclineSignalIsKnown(): void
    {
        $signals = SignalSet::fromArray(['decline' => 0.5, 'low_ctr' => 0.5]);

        self::assertTrue($this->calculator->calculate($signals, Weights::defaults())->isReliable());
    }

    public function testAFreshInstanceWithoutImpressionDataStillProducesReliableScores(): void
    {
        // Thumbnail reach reports only start accumulating once the reporting job
        // exists: the click-through rate is simply unknown for a while.
        $signals = SignalSet::fromArray(['decline' => 0.7, 'potential' => 0.4]);

        self::assertTrue($this->calculator->calculate($signals, Weights::defaults())->isReliable());
    }

    public function testAScoreWithoutTheDeclineSignalIsNotReliable(): void
    {
        $signals = SignalSet::fromArray(['low_ctr' => 0.9, 'impressions' => 0.9, 'potential' => 0.9]);

        self::assertFalse($this->calculator->calculate($signals, Weights::defaults())->isReliable());
    }

    public function testSignalsWhoseWeightIsZeroDoNotContribute(): void
    {
        $weights = Weights::fromArray(['decline' => 0, 'low_ctr' => 100, 'impressions' => 0, 'potential' => 0]);
        $signals = SignalSet::fromArray(['decline' => 1.0, 'low_ctr' => 0.25, 'impressions' => 1.0, 'potential' => 1.0]);

        self::assertSame(25, $this->calculator->calculate($signals, $weights)->value);
    }

    public function testTheScoreKeepsTheSignalsItWasComputedFrom(): void
    {
        $signals = SignalSet::fromArray(['decline' => 0.4]);

        $score = $this->calculator->calculate($signals, Weights::defaults());

        self::assertSame(0.4, $score->signals->get(Signal::Decline));
    }
}
