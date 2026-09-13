<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Input;

use App\Settings\Input\GenerationSettingsInput;
use App\Settings\Input\ScoringSettingsInput;
use App\Settings\SettingKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScoringSettingsInput::class)]
#[CoversClass(GenerationSettingsInput::class)]
final class SettingsInputTest extends TestCase
{
    public function testAnEmptyScoringPayloadFallsBackOnTheDefaults(): void
    {
        $input = ScoringSettingsInput::fromArray([]);

        self::assertSame(['decline' => 30, 'low_ctr' => 30, 'impressions' => 15, 'potential' => 25], $input->weights);
        self::assertSame(60, $input->minAgeDays);
        self::assertSame(28, $input->windowDays);
        self::assertSame(30, $input->warmupDays);
        self::assertSame(0.5, $input->ctrFloorRatio);
        self::assertSame(2.0, $input->retentionTargetRatio);
        self::assertSame(28, $input->relaunchCooldownDays);
    }

    public function testScoringValuesAreClampedRatherThanRejected(): void
    {
        $input = ScoringSettingsInput::fromArray([
            'weights' => ['decline' => 500, 'low_ctr' => -20],
            'minAgeDays' => -5,
            'windowDays' => 2,
            'warmupDays' => 9999,
            'ctrFloorRatio' => 0.0,
            'retentionTargetRatio' => 99,
            'relaunchCooldownDays' => -1,
        ]);

        self::assertSame(100, $input->weights['decline']);
        self::assertSame(0, $input->weights['low_ctr']);
        self::assertSame(0, $input->minAgeDays);
        self::assertSame(ScoringSettingsInput::WINDOW_MIN, $input->windowDays);
        self::assertSame(ScoringSettingsInput::WARMUP_MAX, $input->warmupDays);
        self::assertSame(ScoringSettingsInput::CTR_FLOOR_MIN, $input->ctrFloorRatio);
        self::assertSame(ScoringSettingsInput::RETENTION_TARGET_MAX, $input->retentionTargetRatio);
        self::assertSame(0, $input->relaunchCooldownDays);
    }

    public function testAllWeightsAtZeroFallsBackOnTheDefaults(): void
    {
        $input = ScoringSettingsInput::fromArray([
            'weights' => ['decline' => 0, 'low_ctr' => 0, 'impressions' => 0, 'potential' => 0],
        ]);

        self::assertSame(30, $input->weights['decline']);
    }

    public function testScoringNumbersSurviveAsStrings(): void
    {
        $input = ScoringSettingsInput::fromArray(['minAgeDays' => '90', 'ctrFloorRatio' => '0,5']);

        self::assertSame(90, $input->minAgeDays);
        // A comma is not numeric in PHP, so the default applies rather than 0.
        self::assertSame(0.5, $input->ctrFloorRatio);
    }

    public function testScoringInputMapsOntoSettingKeys(): void
    {
        $settings = ScoringSettingsInput::fromArray([])->toSettings();

        self::assertArrayHasKey(SettingKey::ScoringWeights->value, $settings);
        self::assertArrayHasKey(SettingKey::ScoringRelaunchCooldownDays->value, $settings);
        self::assertCount(7, $settings);
    }

    public function testAnEmptyGenerationPayloadFallsBackOnTheDefaults(): void
    {
        $input = GenerationSettingsInput::fromArray([]);

        self::assertSame('', $input->textModel, 'An empty model means "use the environment value".');
        self::assertSame('', $input->imageModel);
        self::assertSame(250, $input->analyticsRequestDelayMs);
        self::assertSame(1.5, $input->successViewsRatio);
        self::assertSame(0.8, $input->neutralFloorRatio);
        self::assertSame(0.8, $input->negativeCtrRatio);
    }

    public function testModelNamesAreSanitised(): void
    {
        $input = GenerationSettingsInput::fromArray([
            'textModel' => '  gemini-3.1-flash-lite  ',
            'imageModel' => 'gemini/../3.1 flash image<script>',
        ]);

        self::assertSame('gemini-3.1-flash-lite', $input->textModel);
        self::assertSame('gemini..3.1flashimagescript', $input->imageModel);
    }

    public function testTheSuccessThresholdStaysAboveTheNeutralFloor(): void
    {
        $input = GenerationSettingsInput::fromArray([
            'neutralFloorRatio' => 0.9,
            'successViewsRatio' => 0.5,
        ]);

        self::assertSame(0.9, $input->neutralFloorRatio);
        self::assertGreaterThan($input->neutralFloorRatio, $input->successViewsRatio);
    }

    public function testTheAnalyticsDelayIsBounded(): void
    {
        self::assertSame(GenerationSettingsInput::DELAY_MAX, GenerationSettingsInput::fromArray(['analyticsRequestDelayMs' => 99999])->analyticsRequestDelayMs);
        self::assertSame(0, GenerationSettingsInput::fromArray(['analyticsRequestDelayMs' => -10])->analyticsRequestDelayMs);
    }

    public function testGenerationInputMapsOntoSettingKeys(): void
    {
        $settings = GenerationSettingsInput::fromArray([])->toSettings();

        self::assertArrayHasKey(SettingKey::GeminiImageModel->value, $settings);
        self::assertArrayHasKey(SettingKey::VerdictNegativeCtrRatio->value, $settings);
        self::assertCount(6, $settings);
    }
}
