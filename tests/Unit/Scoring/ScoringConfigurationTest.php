<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring;

use App\Scoring\Model\Signal;
use App\Scoring\ScoringConfiguration;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScoringConfiguration::class)]
final class ScoringConfigurationTest extends TestCase
{
    public function testItBuildsTheDefaultWeightsAndParameters(): void
    {
        $configuration = self::configuration();

        self::assertSame(30.0, $configuration->weights()->get(Signal::Decline));
        self::assertSame(60, $configuration->parameters()->minAgeDays);
        self::assertSame(28, $configuration->parameters()->windowDays);
    }

    public function testItAppliesTheStoredWeights(): void
    {
        $configuration = self::configuration([
            SettingKey::ScoringWeights->value => ['decline' => 50, 'low_ctr' => 10],
        ]);

        self::assertSame(50.0, $configuration->weights()->get(Signal::Decline));
        self::assertSame(10.0, $configuration->weights()->get(Signal::LowCtr));
        self::assertSame(15.0, $configuration->weights()->get(Signal::Impressions));
    }

    public function testItFallsBackOnTheDefaultWeightsWhenTheStoredOnesAreInvalid(): void
    {
        $configuration = self::configuration([
            SettingKey::ScoringWeights->value => ['decline' => -5],
        ]);

        self::assertSame(30.0, $configuration->weights()->get(Signal::Decline));
    }

    public function testItAppliesTheStoredParameters(): void
    {
        $configuration = self::configuration([
            SettingKey::ScoringMinAgeDays->value => 90,
            SettingKey::ScoringWindowDays->value => 14,
            SettingKey::ScoringWarmupDays->value => 7,
            SettingKey::ScoringCtrFloorRatio->value => 0.3,
            SettingKey::ScoringRetentionTargetRatio->value => 1.5,
            SettingKey::ScoringRelaunchCooldownDays->value => 42,
        ]);

        $parameters = $configuration->parameters();

        self::assertSame(90, $parameters->minAgeDays);
        self::assertSame(14, $parameters->windowDays);
        self::assertSame(7, $parameters->warmupDays);
        self::assertSame(0.3, $parameters->ctrFloorRatio);
        self::assertSame(1.5, $parameters->retentionTargetRatio);
        self::assertSame(42, $parameters->relaunchCooldownDays);
    }

    public function testItFallsBackOnTheDefaultParametersWhenTheStoredOnesAreInvalid(): void
    {
        $configuration = self::configuration([SettingKey::ScoringWindowDays->value => 0]);

        self::assertSame(28, $configuration->parameters()->windowDays);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private static function configuration(array $stored = []): ScoringConfiguration
    {
        return new ScoringConfiguration(new Settings(new InMemorySettingStore($stored), 'text', 'image'));
    }
}
