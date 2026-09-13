<?php

declare(strict_types=1);

namespace App\Tests\Unit\Thumbnail\Generation;

use App\Analysis\Model\ThumbnailAngle;
use App\Settings\Entity\ReferenceAngle;
use App\Settings\Locale\ContentLanguage;
use App\Settings\Locale\LocaleResolver;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use App\Thumbnail\Generation\ThumbnailPromptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ThumbnailPromptBuilder::class)]
final class ThumbnailPromptBuilderTest extends TestCase
{
    private ThumbnailPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = self::builder('en');
    }

    public function testThePromptKeepsTheCreativePartAndAddsTheConstraints(): void
    {
        $prompt = $this->builder->forAngle(self::angle(), '');

        self::assertStringContainsString('A close-up of the host', $prompt);
        self::assertStringContainsString('16:9 YouTube thumbnail', $prompt);
        self::assertStringContainsString('"ENFIN COMPRIS"', $prompt);
        self::assertStringContainsString('three-quarter view turned to their right', $prompt);
        self::assertStringContainsString('Facial expression: surpris', $prompt);
        self::assertStringContainsString('bottom-right corner', $prompt);
        self::assertStringContainsString('No other text anywhere', $prompt);
        self::assertStringContainsString('The text is in English.', $prompt);
    }

    /**
     * The burnt-in words are read by the audience, so the image model is told to
     * spell them in the language the creator set for the application.
     */
    public function testTheOverlayLanguageFollowsTheInterface(): void
    {
        $prompt = self::builder('fr')->forAngle(self::angle(), '');

        self::assertStringContainsString('The text is in French.', $prompt);
        self::assertStringNotContainsString('in English', $prompt);
    }

    /**
     * Gemini drops the entire image when the prompt names the person, so the
     * identity must be asked for through the reference photos alone.
     */
    public function testThePromptNamesNobody(): void
    {
        $prompt = $this->builder->forAngle(self::angle(), 'Ma chaîne à moi');

        self::assertStringNotContainsString('host of the channel', $prompt);
        self::assertStringContainsString('the creator shown in the reference photos', $prompt);
    }

    public function testTheStyleGuidelinesAreAppendedWhenThereAreSome(): void
    {
        $prompt = $this->builder->forAngle(self::angle(), '  Fond sombre, typo jaune.  ');

        self::assertStringContainsString('Channel style to respect:', $prompt);
        self::assertStringContainsString('Fond sombre, typo jaune.', $prompt);
    }

    public function testTheStyleSectionIsOmittedWhenEmpty(): void
    {
        self::assertStringNotContainsString('Channel style', $this->builder->forAngle(self::angle(), '   '));
    }

    public function testAMissingExpressionFallsBackToSomethingUsable(): void
    {
        $angle = new ThumbnailAngle(0, 'TEXTE', 'Scène', '', ReferenceAngle::Front, 'A prompt');

        self::assertStringContainsString('Facial expression: engaging', $this->builder->forAngle($angle, ''));
    }

    public function testTheIterationPromptPinsWhatMustNotChange(): void
    {
        $prompt = $this->builder->forIteration('  Agrandis le texte  ', 'ENFIN COMPRIS', '');

        self::assertStringContainsString('Agrandis le texte', $prompt);
        self::assertStringContainsString('identity of the face', $prompt);
        self::assertStringContainsString('"ENFIN COMPRIS"', $prompt);
        self::assertStringContainsString('16:9', $prompt);
    }

    public function testTheIterationPromptWorksWithoutOverlayText(): void
    {
        $prompt = $this->builder->forIteration('Change le fond', '   ', 'Fond sombre.');

        self::assertStringNotContainsString('spelled character for character, unless', $prompt);
        self::assertStringContainsString('Fond sombre.', $prompt);
    }

    /**
     * `ContentLanguage` reads the language from the settings, so the double is a
     * real one built on an in-memory store holding the locale under test.
     */
    private static function builder(string $locale): ThumbnailPromptBuilder
    {
        $settings = new Settings(
            new InMemorySettingStore([SettingKey::Locale->value => $locale]),
            'gemini-3.1-flash-lite',
            'gemini-3.1-flash-image',
        );

        return new ThumbnailPromptBuilder(new ContentLanguage(new LocaleResolver($settings, new NullLogger())));
    }

    private static function angle(): ThumbnailAngle
    {
        return new ThumbnailAngle(
            0,
            'ENFIN COMPRIS',
            'Gros plan sur le visage',
            'surpris',
            ReferenceAngle::Right,
            'A close-up of the host against a dark background',
        );
    }
}
