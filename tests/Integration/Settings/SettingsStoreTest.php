<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\DoctrineSettingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(DoctrineSettingStore::class)]
final class SettingsStoreTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Settings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(Settings::class);
    }

    public function testAFreshInstallAnswersWithTheDefaults(): void
    {
        self::assertSame(60, $this->settings->getInt(SettingKey::ScoringMinAgeDays));
        self::assertFalse($this->settings->isOnboardingCompleted());
    }

    public function testAWrittenSettingSurvivesANewSnapshot(): void
    {
        $this->settings->set(SettingKey::ScoringMinAgeDays, 120);
        $this->settings->reset();

        self::assertSame(120, $this->settings->getInt(SettingKey::ScoringMinAgeDays));
    }

    public function testWritingTwiceUpdatesTheSameRow(): void
    {
        $this->settings->set(SettingKey::StyleGuidelines, 'Première version');
        $this->settings->set(SettingKey::StyleGuidelines, 'Deuxième version');

        self::assertSame('Deuxième version', $this->settings->getString(SettingKey::StyleGuidelines));
    }

    public function testStructuredValuesRoundTripThroughJson(): void
    {
        $this->settings->set(SettingKey::ScoringWeights, ['decline' => 40, 'low_ctr' => 20]);
        $this->settings->reset();

        self::assertSame(['decline' => 40, 'low_ctr' => 20], $this->settings->getArray(SettingKey::ScoringWeights));
    }

    public function testClearingASettingBringsBackItsDefault(): void
    {
        $this->settings->set(SettingKey::ScoringMinAgeDays, 120);

        $this->settings->clear(SettingKey::ScoringMinAgeDays);

        self::assertSame(60, $this->settings->getInt(SettingKey::ScoringMinAgeDays));
    }

    public function testClearingAnUnsetSettingIsHarmless(): void
    {
        $this->settings->clear(SettingKey::StyleGuidelines);

        self::assertSame('', $this->settings->getString(SettingKey::StyleGuidelines));
    }
}
