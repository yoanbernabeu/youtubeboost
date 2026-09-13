<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Gemini;

use App\Analysis\Gemini\AnalysisPromptBuilder;
use App\Analysis\Gemini\ThumbnailPlanSchema;
use App\Analysis\Model\VideoBrief;
use App\Settings\Entity\ReferenceAngle;
use App\Settings\Locale\ContentLanguage;
use App\Settings\Locale\LocaleResolver;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AnalysisPromptBuilder::class)]
#[CoversClass(VideoBrief::class)]
final class AnalysisPromptBuilderTest extends TestCase
{
    private AnalysisPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = self::builder('en');
    }

    public function testTheSystemPromptStatesTheHardRules(): void
    {
        $prompt = $this->builder->systemPrompt();

        self::assertStringContainsString('exactly 5 angles', $prompt);
        self::assertStringContainsString('ENGLISH, 2 to 4 words', $prompt);
        self::assertStringContainsString('16:9', $prompt);
        self::assertStringContainsString('front, right, left', $prompt);
        self::assertStringContainsString('bottom-right corner', $prompt);
    }

    /**
     * The overlay words are read by the audience, so they follow the language the
     * creator set for the application, not a language hardcoded in the prompt.
     */
    public function testTheOverlayLanguageFollowsTheInterface(): void
    {
        $prompt = self::builder('fr')->systemPrompt();

        self::assertStringContainsString('FRENCH, 2 to 4 words', $prompt);
        self::assertStringContainsString('French-speaking', $prompt);
        self::assertStringNotContainsString('ENGLISH', $prompt);
    }

    /**
     * The image prompt is the one piece that stays English whatever the interface
     * says: it is read by the image model, never by the audience.
     */
    public function testTheImagePromptStaysInEnglish(): void
    {
        self::assertStringContainsString('`imagePrompt` is in English', self::builder('fr')->systemPrompt());
    }

    public function testTheAnswerSchemaAsksForTheSameLanguage(): void
    {
        $schema = self::builder('fr')->responseSchema();

        self::assertSame(
            ThumbnailPlanSchema::definition(AnalysisPromptBuilder::ANGLE_COUNT, 'French'),
            $schema,
        );
        self::assertStringContainsString('French', json_encode($schema, \JSON_THROW_ON_ERROR));
    }

    public function testTheUserPromptCarriesTheVideoAndItsTranscript(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(transcript: 'On parle de miniatures.'));

        self::assertStringContainsString('Ma chaîne', $prompt);
        self::assertStringContainsString('Title: Comment relancer une vidéo', $prompt);
        self::assertStringContainsString('Published: 2024-03-15', $prompt);
        self::assertStringContainsString('Duration: 15:33', $prompt);
        self::assertStringContainsString('On parle de miniatures.', $prompt);
        self::assertStringContainsString('1 200 vues sur 28 jours', $prompt);
    }

    public function testTheUserPromptWarnsWhenThereIsNoTranscript(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(transcript: null));

        self::assertStringContainsString('Not available', $prompt);
        self::assertStringContainsString('stay cautious', $prompt);
    }

    public function testAWhitespaceOnlyTranscriptCountsAsAbsent(): void
    {
        self::assertStringContainsString('Not available', $this->builder->userPrompt(self::brief(transcript: "  \n ")));
    }

    public function testTheStyleGuidelinesAreIncludedWhenThereAreSome(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(styleGuidelines: 'Fond sombre, typographie jaune.'));

        self::assertStringContainsString('Channel style guidelines', $prompt);
        self::assertStringContainsString('Fond sombre, typographie jaune.', $prompt);
    }

    public function testTheStyleSectionIsOmittedWhenEmpty(): void
    {
        self::assertStringNotContainsString('style guidelines', $this->builder->userPrompt(self::brief(styleGuidelines: '   ')));
    }

    public function testTheAvailableAnglesAreListedWithTheirHint(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(angles: [ReferenceAngle::Front, ReferenceAngle::Left]));

        self::assertStringContainsString('- front (face-on, looking straight at the camera)', $prompt);
        self::assertStringContainsString('- left (three-quarter view turned to their left)', $prompt);
        self::assertStringNotContainsString('- right (', $prompt);
    }

    public function testTheOtherAngleIsNotOfferedToTheModel(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(angles: [ReferenceAngle::Other]));

        self::assertStringContainsString('None.', $prompt);
    }

    public function testAnEmptyDescriptionIsMarkedAsSuch(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(description: ''));

        self::assertStringContainsString('(empty)', $prompt);
    }

    public function testAnEmptyStatsSummaryIsMarkedAsSuch(): void
    {
        $prompt = $this->builder->userPrompt(self::brief(statsSummary: ''));

        self::assertStringContainsString('(no data)', $prompt);
    }

    /**
     * `ContentLanguage` reads the language from the settings, so the double is a
     * real one built on an in-memory store holding the locale under test.
     */
    private static function builder(string $locale): AnalysisPromptBuilder
    {
        return new AnalysisPromptBuilder(self::language($locale));
    }

    private static function language(string $locale): ContentLanguage
    {
        $settings = new Settings(
            new InMemorySettingStore([SettingKey::Locale->value => $locale]),
            'gemini-3.1-flash-lite',
            'gemini-3.1-flash-image',
        );

        return new ContentLanguage(new LocaleResolver($settings, new NullLogger()));
    }

    /**
     * @param list<ReferenceAngle> $angles
     */
    private static function brief(
        ?string $transcript = 'Transcript.',
        string $styleGuidelines = '',
        string $description = 'Une description.',
        string $statsSummary = '1 200 vues sur 28 jours',
        array $angles = [ReferenceAngle::Front, ReferenceAngle::Right, ReferenceAngle::Left],
    ): VideoBrief {
        return new VideoBrief(
            'Ma chaîne',
            'Comment relancer une vidéo',
            $description,
            new \DateTimeImmutable('2024-03-15'),
            933,
            $transcript,
            $styleGuidelines,
            $statsSummary,
            $angles,
        );
    }
}
