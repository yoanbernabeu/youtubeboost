<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Settings::class)]
#[CoversClass(SettingKey::class)]
#[CoversClass(InMemorySettingStore::class)]
final class SettingsTest extends TestCase
{
    public function testItFallsBackOnTheDefaultOfEachKey(): void
    {
        $settings = self::settings();

        self::assertSame(60, $settings->getInt(SettingKey::ScoringMinAgeDays));
        self::assertSame(0.5, $settings->getFloat(SettingKey::ScoringCtrFloorRatio));
        self::assertSame('', $settings->getString(SettingKey::StyleGuidelines));
        self::assertSame(250, $settings->getInt(SettingKey::AnalyticsRequestDelayMs));
    }

    public function testItReturnsTheStoredValueWhenThereIsOne(): void
    {
        $settings = self::settings([SettingKey::ScoringMinAgeDays->value => 90]);

        self::assertSame(90, $settings->getInt(SettingKey::ScoringMinAgeDays));
    }

    public function testItCoercesStoredValuesToTheExpectedType(): void
    {
        $settings = self::settings([
            SettingKey::ScoringMinAgeDays->value => '90',
            SettingKey::ScoringCtrFloorRatio->value => 1,
            SettingKey::StyleGuidelines->value => 42,
        ]);

        self::assertSame(90, $settings->getInt(SettingKey::ScoringMinAgeDays));
        self::assertSame(1.0, $settings->getFloat(SettingKey::ScoringCtrFloorRatio));
        self::assertSame('42', $settings->getString(SettingKey::StyleGuidelines));
    }

    public function testTheGeminiModelsDefaultToTheirEnvironmentValue(): void
    {
        $settings = self::settings();

        self::assertSame('gemini-text-from-env', $settings->getTextModel());
        self::assertSame('gemini-image-from-env', $settings->getImageModel());
    }

    public function testTheGeminiModelsCanBeOverriddenFromTheSettings(): void
    {
        $settings = self::settings([SettingKey::GeminiTextModel->value => 'gemini-other']);

        self::assertSame('gemini-other', $settings->getTextModel());
        self::assertSame('gemini-image-from-env', $settings->getImageModel());
    }

    public function testAnEmptyStoredModelFallsBackOnTheEnvironment(): void
    {
        $settings = self::settings([SettingKey::GeminiTextModel->value => '  ']);

        self::assertSame('gemini-text-from-env', $settings->getTextModel());
    }

    public function testWeightsAreReadAsAnArray(): void
    {
        $settings = self::settings([SettingKey::ScoringWeights->value => ['decline' => 40]]);

        self::assertSame(['decline' => 40], $settings->getArray(SettingKey::ScoringWeights));
    }

    public function testAnAbsentArrayReadsAsEmpty(): void
    {
        self::assertSame([], self::settings()->getArray(SettingKey::ScoringWeights));
    }

    public function testWritingASettingMakesItReadableImmediately(): void
    {
        $settings = self::settings();

        $settings->set(SettingKey::ScoringMinAgeDays, 120);

        self::assertSame(120, $settings->getInt(SettingKey::ScoringMinAgeDays));
    }

    public function testWritingSeveralSettingsAtOnce(): void
    {
        $settings = self::settings();

        $settings->setMany([
            SettingKey::ScoringMinAgeDays->value => 120,
            SettingKey::StyleGuidelines->value => 'Fond sombre, gros titres.',
        ]);

        self::assertSame(120, $settings->getInt(SettingKey::ScoringMinAgeDays));
        self::assertSame('Fond sombre, gros titres.', $settings->getString(SettingKey::StyleGuidelines));
    }

    public function testWritingAnUnknownKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::settings()->setMany(['not.a.key' => 1]);
    }

    public function testOnboardingIsIncompleteUntilItIsMarkedDone(): void
    {
        $settings = self::settings();
        self::assertFalse($settings->isOnboardingCompleted());
        self::assertNull($settings->getDateTime(SettingKey::OnboardingCompletedAt));

        $settings->set(SettingKey::OnboardingCompletedAt, '2026-06-30T10:00:00+00:00');

        self::assertTrue($settings->isOnboardingCompleted());
        self::assertSame('2026-06-30', $settings->getDateTime(SettingKey::OnboardingCompletedAt)?->format('Y-m-d'));
    }

    public function testAnUnparsableDateReadsAsNull(): void
    {
        $settings = self::settings([SettingKey::OnboardingCompletedAt->value => 'not a date']);

        self::assertNull($settings->getDateTime(SettingKey::OnboardingCompletedAt));
        self::assertFalse($settings->isOnboardingCompleted());
    }

    public function testResettingASettingBringsBackItsDefault(): void
    {
        $settings = self::settings([SettingKey::ScoringMinAgeDays->value => 90]);

        $settings->clear(SettingKey::ScoringMinAgeDays);

        self::assertSame(60, $settings->getInt(SettingKey::ScoringMinAgeDays));
    }

    public function testGeminiIsConsideredConfiguredOnlyWithAKey(): void
    {
        self::assertFalse(self::settings()->isGeminiConfigured());
        self::assertFalse(self::settings(apiKey: '   ')->isGeminiConfigured());
        self::assertTrue(self::settings(apiKey: 'AIza-test')->isGeminiConfigured());
    }

    /**
     * @param array<string, mixed> $stored
     */
    private static function settings(array $stored = [], string $apiKey = ''): Settings
    {
        return new Settings(new InMemorySettingStore($stored), 'gemini-text-from-env', 'gemini-image-from-env', $apiKey);
    }
}
