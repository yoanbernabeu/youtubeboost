<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Gemini;

use App\Analysis\Exception\AnalysisFailedException;
use App\Analysis\Gemini\AnalysisPromptBuilder;
use App\Analysis\Gemini\GeminiVideoAnalyzer;
use App\Analysis\Gemini\ThumbnailPlanSchema;
use App\Analysis\Model\AnalysisPlan;
use App\Analysis\Model\ThumbnailAngle;
use App\Analysis\Model\VideoBrief;
use App\Settings\Entity\ReferenceAngle;
use App\Settings\Locale\ContentLanguage;
use App\Settings\Locale\LocaleResolver;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use App\Tests\Support\Json;
use App\Tests\Support\RequestRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Gemini\Factory;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(GeminiVideoAnalyzer::class)]
#[CoversClass(ThumbnailPlanSchema::class)]
#[CoversClass(AnalysisPlan::class)]
#[CoversClass(ThumbnailAngle::class)]
final class GeminiVideoAnalyzerTest extends TestCase
{
    public function testItReturnsTheFiveAnglesTheModelProposed(): void
    {
        $plan = self::analyzer(self::planResponse())->analyze(self::brief());

        self::assertSame('Un résumé en trois phrases.', $plan->summary);
        self::assertSame('La promesse de la vidéo.', $plan->promise);
        self::assertCount(5, $plan->angles);
        self::assertSame('gemini-3.1-flash-lite', $plan->model);

        $first = $plan->angles[0];
        self::assertSame(0, $first->index);
        self::assertSame('ENFIN COMPRIS', $first->overlayText);
        self::assertSame(ReferenceAngle::Front, $first->referenceAngle);
        self::assertStringContainsString('close-up', $first->imagePrompt);
        self::assertSame(4, $plan->angles[4]->index);
    }

    public function testItSendsTheSchemaAndTheConfiguredModel(): void
    {
        $recorder = new RequestRecorder();
        self::analyzer(self::planResponse(), $recorder, ['gemini.text_model' => 'gemini-3.5-flash'])->analyze(self::brief());

        self::assertStringContainsString('/v1beta/models/gemini-3.5-flash:generateContent', $recorder->url());

        $body = $recorder->body();
        self::assertSame('application/json', Json::get($body, 'generationConfig.responseMimeType'));
        self::assertSame(5, Json::get($body, 'generationConfig.responseJsonSchema.properties.angles.minItems'));
        self::assertSame(
            ['front', 'right', 'left'],
            Json::get($body, 'generationConfig.responseJsonSchema.properties.angles.items.properties.referenceAngle.enum'),
        );
        self::assertStringContainsString('exactly 5 angles', Json::getString($body, 'system_instruction.parts.0.text'));
    }

    public function testItAcceptsAnAnswerWrappedInAMarkdownFence(): void
    {
        $json = json_encode(self::plan(), \JSON_UNESCAPED_UNICODE);
        $analyzer = self::analyzer(self::geminiTextResponse("```json\n" . $json . "\n```"));

        self::assertCount(5, $analyzer->analyze(self::brief())->angles);
    }

    public function testItRejectsAnAnswerThatIsNotJson(): void
    {
        $analyzer = self::analyzer(self::geminiTextResponse('désolé, je ne peux pas'));

        $this->expectException(AnalysisFailedException::class);
        $this->expectExceptionMessageMatches('#cannot be used#');

        $analyzer->analyze(self::brief());
    }

    public function testItRejectsAnAnswerWithoutAnyUsableAngle(): void
    {
        $analyzer = self::analyzer(self::geminiTextResponse((string) json_encode([
            'summary' => 'Résumé',
            'promise' => 'Promesse',
            'angles' => [['overlayText' => 'SANS PROMPT']],
        ])));

        $this->expectException(AnalysisFailedException::class);
        $this->expectExceptionMessageMatches('#no usable thumbnail angle#');

        $analyzer->analyze(self::brief());
    }

    public function testItSkipsMalformedAnglesButKeepsTheGoodOnes(): void
    {
        $plan = self::plan();
        $plan['angles'][1] = 'not an object';
        $analyzer = self::analyzer(self::geminiTextResponse((string) json_encode($plan, \JSON_UNESCAPED_UNICODE)));

        self::assertCount(4, $analyzer->analyze(self::brief())->angles);
    }

    public function testAnUnreachableModelIsReported(): void
    {
        $analyzer = self::analyzer(new JsonMockResponse(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'overloaded']], ['http_code' => 503]));

        $this->expectException(AnalysisFailedException::class);
        $this->expectExceptionMessageMatches('#could not analyse the video#');

        $analyzer->analyze(self::brief());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function analyzer(JsonMockResponse $response, ?RequestRecorder $recorder = null, array $settings = []): GeminiVideoAnalyzer
    {
        $recorder ??= new RequestRecorder();

        $store = new Settings(new InMemorySettingStore($settings), 'gemini-3.1-flash-lite', 'gemini-3.1-flash-image');

        return new GeminiVideoAnalyzer(
            Factory::createPlatform('test-key', $recorder->client([$response])),
            new AnalysisPromptBuilder(new ContentLanguage(new LocaleResolver($store, new NullLogger()))),
            $store,
        );
    }

    private static function planResponse(): JsonMockResponse
    {
        return self::geminiTextResponse((string) json_encode(self::plan(), \JSON_UNESCAPED_UNICODE));
    }

    private static function geminiTextResponse(string $text): JsonMockResponse
    {
        return new JsonMockResponse([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
                'finishReason' => 'STOP',
            ]],
        ]);
    }

    /**
     * @return array{summary: string, promise: string, angles: list<mixed>}
     */
    private static function plan(): array
    {
        $angle = static fn (int $i): array => [
            'overlayText' => 0 === $i ? 'ENFIN COMPRIS' : 'ANGLE ' . $i,
            'sceneDescription' => 'Description de la scène ' . $i,
            'faceExpression' => 'surpris',
            'referenceAngle' => ['front', 'right', 'left'][$i % 3],
            'imagePrompt' => 'A close-up of the host, 16:9 YouTube thumbnail, angle ' . $i,
        ];

        return [
            'summary' => 'Un résumé en trois phrases.',
            'promise' => 'La promesse de la vidéo.',
            'angles' => array_map($angle, range(0, 4)),
        ];
    }

    private static function brief(): VideoBrief
    {
        return new VideoBrief(
            'Ma chaîne',
            'Comment relancer une vidéo',
            'Une description.',
            new \DateTimeImmutable('2024-03-15'),
            933,
            'Transcript complet.',
            'Fond sombre.',
            '1 200 vues sur 28 jours',
            [ReferenceAngle::Front, ReferenceAngle::Right, ReferenceAngle::Left],
        );
    }
}
