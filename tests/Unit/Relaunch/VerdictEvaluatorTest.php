<?php

declare(strict_types=1);

namespace App\Tests\Unit\Relaunch;

use App\Relaunch\Model\Comparison;
use App\Relaunch\Model\PerformanceSnapshot;
use App\Relaunch\Model\Verdict;
use App\Relaunch\Model\VerdictThresholds;
use App\Relaunch\Tracking\VerdictEvaluator;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerdictEvaluator::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(VerdictThresholds::class)]
#[CoversClass(Comparison::class)]
#[CoversClass(PerformanceSnapshot::class)]
final class VerdictEvaluatorTest extends TestCase
{
    private VerdictEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new VerdictEvaluator();
    }

    #[DataProvider('verdicts')]
    public function testTheVerdictFollowsTheDefaultRules(float $viewsBefore, float $viewsAfter, ?float $ctrBefore, ?float $ctrAfter, Verdict $expected): void
    {
        $comparison = new Comparison(
            new PerformanceSnapshot(28, $viewsBefore, null, $ctrBefore, null, null),
            new PerformanceSnapshot(14, $viewsAfter, null, $ctrAfter, null, null),
        );

        self::assertSame($expected, $this->evaluator->evaluate($comparison, VerdictThresholds::defaults()));
    }

    /**
     * @return iterable<string, array{float, float, float|null, float|null, Verdict}>
     */
    public static function verdicts(): iterable
    {
        yield 'views doubled and CTR up' => [100.0, 200.0, 4.0, 5.0, Verdict::Successful];
        yield 'views exactly one and a half times with equal CTR' => [100.0, 150.0, 4.0, 4.0, Verdict::Successful];
        yield 'views doubled but no CTR data' => [100.0, 200.0, null, null, Verdict::Successful];
        yield 'views doubled but CTR slightly down' => [100.0, 200.0, 4.0, 3.8, Verdict::Neutral];
        yield 'views flat' => [100.0, 100.0, 4.0, 4.0, Verdict::Neutral];
        yield 'views up a little' => [100.0, 120.0, 4.0, 4.2, Verdict::Neutral];
        yield 'views just above the floor' => [100.0, 80.0, 4.0, 4.0, Verdict::Neutral];
        yield 'views below the floor' => [100.0, 79.0, 4.0, 4.0, Verdict::Negative];
        yield 'views collapsed' => [100.0, 10.0, null, null, Verdict::Negative];
        yield 'views fine but CTR collapsed' => [100.0, 120.0, 4.0, 3.0, Verdict::Negative];
        yield 'views doubled but CTR collapsed' => [100.0, 300.0, 4.0, 2.0, Verdict::Negative];
    }

    public function testWithoutAUsableBeforeNothingIsClaimed(): void
    {
        $comparison = new Comparison(PerformanceSnapshot::empty(28), new PerformanceSnapshot(14, 500.0, null, null, null, null));

        self::assertSame(Verdict::Neutral, $this->evaluator->evaluate($comparison, VerdictThresholds::defaults()));
    }

    public function testTheThresholdsCanBeTightened(): void
    {
        $comparison = new Comparison(
            new PerformanceSnapshot(28, 100.0, null, null, null, null),
            new PerformanceSnapshot(14, 250.0, null, null, null, null),
        );

        self::assertSame(Verdict::Successful, $this->evaluator->evaluate($comparison, VerdictThresholds::defaults()));
        self::assertSame(Verdict::Neutral, $this->evaluator->evaluate($comparison, new VerdictThresholds(3.0, 0.9, 0.9)));
    }

    public function testOnlyANegativeVerdictSuggestsRollingBack(): void
    {
        self::assertTrue(Verdict::Negative->suggestsRevert());
        self::assertFalse(Verdict::Neutral->suggestsRevert());
        self::assertFalse(Verdict::Successful->suggestsRevert());
        self::assertSame('relaunch.verdict.successful', Verdict::Successful->label()->getMessage());
    }

    public function testTheThresholdsComeFromTheSettings(): void
    {
        $settings = new Settings(new InMemorySettingStore([
            SettingKey::VerdictSuccessViewsRatio->value => 2.0,
            SettingKey::VerdictNeutralFloorRatio->value => 0.9,
            SettingKey::VerdictNegativeCtrRatio->value => 0.95,
        ]), 'text', 'image');

        $thresholds = VerdictThresholds::fromSettings($settings);

        self::assertSame(2.0, $thresholds->successViewsRatio);
        self::assertSame(0.9, $thresholds->neutralFloorRatio);
        self::assertSame(0.95, $thresholds->negativeCtrRatio);
    }

    public function testInconsistentStoredThresholdsFallBackOnTheDefaults(): void
    {
        $settings = new Settings(new InMemorySettingStore([
            SettingKey::VerdictSuccessViewsRatio->value => 0.5,
            SettingKey::VerdictNeutralFloorRatio->value => 0.9,
        ]), 'text', 'image');

        self::assertSame(1.5, VerdictThresholds::fromSettings($settings)->successViewsRatio);
    }

    public function testTheComparisonExposesEveryRatio(): void
    {
        $comparison = new Comparison(
            new PerformanceSnapshot(28, 100.0, 1000.0, 4.0, 120.0, 40.0),
            new PerformanceSnapshot(14, 150.0, 1200.0, 5.0, 132.0, 44.0),
        );

        self::assertSame(1.5, $comparison->viewsRatio());
        self::assertSame(1.25, $comparison->clickThroughRateRatio());
        self::assertSame(1.2, $comparison->impressionsRatio());
        self::assertEqualsWithDelta(1.1, $comparison->averageViewDurationRatio(), 0.0001);
        self::assertSame(50.0, $comparison->viewsVariation());
    }

    public function testARatioIsUnknownWhenTheBeforeValueIsMissing(): void
    {
        $comparison = new Comparison(
            new PerformanceSnapshot(28, 100.0, null, null, null, null),
            new PerformanceSnapshot(14, 150.0, 1200.0, 5.0, 132.0, 44.0),
        );

        self::assertNull($comparison->clickThroughRateRatio());
        self::assertNull($comparison->impressionsRatio());
    }

    public function testASnapshotRoundTripsThroughItsStoredForm(): void
    {
        $snapshot = new PerformanceSnapshot(28, 100.5, 1000.0, 4.25, 120.0, 40.0);

        self::assertSame($snapshot->toArray(), PerformanceSnapshot::fromArray($snapshot->toArray())->toArray());
        self::assertSame(0.0, PerformanceSnapshot::fromArray([])->viewsPerDay);
    }
}
