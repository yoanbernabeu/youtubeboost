<?php

declare(strict_types=1);

namespace App\Tests\Unit\Thumbnail\Generation;

use App\Settings\Entity\ReferenceAngle;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use App\Tests\Support\Json;
use App\Tests\Support\RequestRecorder;
use App\Thumbnail\Exception\ImageGenerationFailedException;
use App\Thumbnail\Generation\GeminiImageGenerator;
use App\Thumbnail\Generation\ImageConfigStyle;
use App\Thumbnail\Generation\ImageRequest;
use App\Thumbnail\Generation\ImageShape;
use App\Thumbnail\Generation\ReferenceImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Gemini\Factory;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

#[CoversClass(GeminiImageGenerator::class)]
#[CoversClass(ImageConfigStyle::class)]
#[CoversClass(ImageShape::class)]
#[CoversClass(ImageRequest::class)]
final class GeminiImageGeneratorTest extends TestCase
{
    public function testItSendsThePromptAndEveryReferencePhoto(): void
    {
        $recorder = new RequestRecorder();
        $generator = self::generator([self::imageResponse('PNGBYTES')], $recorder);

        $image = $generator->generate(new ImageRequest('A 16:9 thumbnail', [
            self::reference('FRONT', ReferenceAngle::Front),
            self::reference('RIGHT', ReferenceAngle::Right),
        ]));

        self::assertSame('PNGBYTES', $image->binary);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame('gemini-3.1-flash-image', $image->model);

        $body = $recorder->body();
        self::assertSame('A 16:9 thumbnail', Json::get($body, 'contents.0.parts.0.text'));
        self::assertSame(base64_encode('FRONT'), Json::get($body, 'contents.0.parts.1.inline_data.data'));
        self::assertSame(base64_encode('RIGHT'), Json::get($body, 'contents.0.parts.2.inline_data.data'));
        self::assertSame('image/png', Json::get($body, 'contents.0.parts.1.inline_data.mime_type'));
    }

    public function testItAsksForASixteenByNineImage(): void
    {
        $recorder = new RequestRecorder();
        self::generator([self::imageResponse('PNG')], $recorder)->generate(self::request());

        self::assertSame(['TEXT', 'IMAGE'], Json::get($recorder->body(), 'generationConfig.responseModalities'));
        self::assertSame('16:9', Json::get($recorder->body(), 'generationConfig.imageConfig.aspectRatio'));
        self::assertSame('1K', Json::get($recorder->body(), 'generationConfig.imageConfig.imageSize'));
    }

    public function testAnIterationSendsThePreviousImageInsteadOfTheReferences(): void
    {
        $recorder = new RequestRecorder();
        $generator = self::generator([self::imageResponse('NEW')], $recorder);

        $generator->generate(new ImageRequest(
            'Make the text bigger',
            [self::reference('FRONT', ReferenceAngle::Front)],
            self::reference('PREVIOUS', ReferenceAngle::Other),
        ));

        $parts = Json::get($recorder->body(), 'contents.0.parts');
        self::assertIsArray($parts);
        self::assertCount(2, $parts);
        self::assertSame(base64_encode('PREVIOUS'), Json::get($recorder->body(), 'contents.0.parts.1.inline_data.data'));
    }

    public function testGeneratingWithoutAnyReferencePhotoIsRefusedUpFront(): void
    {
        $generator = self::generator([self::imageResponse('PNG')]);

        $this->expectException(ImageGenerationFailedException::class);
        $this->expectExceptionMessageMatches('#reference photo#');

        $generator->generate(new ImageRequest('A thumbnail'));
    }

    public function testItFallsBackWhenASpellingIsNotRecognised(): void
    {
        $recorder = new RequestRecorder();
        $settings = self::settings([SettingKey::GeminiImageConfigStyle->value => 'response_format']);
        $generator = self::generator([
            new JsonMockResponse([
                'error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received. Unknown name "responseFormat"'],
            ], ['http_code' => 400]),
            self::imageResponse('PNG'),
        ], $recorder, $settings);

        $generator->generate(self::request());

        self::assertSame(2, $recorder->count());
        self::assertSame('16:9', Json::get($recorder->body(1), 'generationConfig.imageConfig.aspectRatio'));
        self::assertSame('image_config', $settings->getString(SettingKey::GeminiImageConfigStyle));
    }

    /**
     * The refusal a real account returns: `responseFormat.image` exists, but its
     * ratio is an enum there, so the `16:9` spelling is rejected on the value and
     * not on the field name. It is still a spelling problem the next style fixes.
     */
    public function testARejectedRatioValueFallsBackToTheOtherSpelling(): void
    {
        $recorder = new RequestRecorder();
        $settings = self::settings([SettingKey::GeminiImageConfigStyle->value => 'response_format']);
        $generator = self::generator([
            new JsonMockResponse(['error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Invalid value at \'generation_config.response_format.image.aspect_ratio\' (type.googleapis.com/google.ai.generativelanguage.v1beta.ImageResponseFormat.AspectRatio), "16:9"',
            ]], ['http_code' => 400]),
            self::imageResponse('PNG'),
        ], $recorder, $settings);

        $image = $generator->generate(self::request());

        self::assertSame('PNG', $image->binary);
        self::assertSame('16:9', Json::get($recorder->body(1), 'generationConfig.imageConfig.aspectRatio'));
        self::assertSame('image_config', $settings->getString(SettingKey::GeminiImageConfigStyle));
    }

    /**
     * A rejected value outside the image configuration is a bug in the request, not
     * a spelling to work around, and retrying it would only hide it.
     */
    public function testARejectedValueOutsideTheImageConfigurationIsNotRetried(): void
    {
        $recorder = new RequestRecorder();
        $generator = self::generator([
            new JsonMockResponse(['error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Invalid value at \'generation_config.response_modalities[0]\' (type.googleapis.com/google.ai.generativelanguage.v1beta.GenerationConfig.Modality), "ZZZ"',
            ]], ['http_code' => 400]),
        ], $recorder);

        try {
            $generator->generate(self::request());
            self::fail('An exception was expected.');
        } catch (ImageGenerationFailedException) {
            self::assertSame(1, $recorder->count());
        }
    }

    public function testTheDocumentedSpellingIsTriedFirst(): void
    {
        $recorder = new RequestRecorder();

        self::generator([self::imageResponse('PNG')], $recorder)->generate(self::request());

        $config = Json::get($recorder->body(), 'generationConfig');
        self::assertIsArray($config);
        self::assertArrayHasKey('imageConfig', $config);
        self::assertArrayNotHasKey('responseFormat', $config);
    }

    /**
     * The newer field types the ratio and the size as protobuf enums, where Google
     * writes the digits out in words. Sending `16:9` there is refused, so each
     * style carries its own vocabulary.
     */
    public function testTheNewerSpellingSendsProtoEnumNames(): void
    {
        $recorder = new RequestRecorder();
        $settings = self::settings([SettingKey::GeminiImageConfigStyle->value => 'response_format']);

        self::generator([self::imageResponse('PNG')], $recorder, $settings)->generate(self::request());

        $body = $recorder->body();
        self::assertSame('ASPECT_RATIO_SIXTEEN_BY_NINE', Json::get($body, 'generationConfig.responseFormat.image.aspectRatio'));
        self::assertSame('IMAGE_SIZE_ONE_K', Json::get($body, 'generationConfig.responseFormat.image.imageSize'));
    }

    public function testTheRememberedStyleIsTriedFirst(): void
    {
        $recorder = new RequestRecorder();
        $settings = self::settings([SettingKey::GeminiImageConfigStyle->value => 'none']);

        self::generator([self::imageResponse('PNG')], $recorder, $settings)->generate(self::request());

        $config = Json::get($recorder->body(), 'generationConfig');
        self::assertIsArray($config);
        self::assertArrayNotHasKey('imageConfig', $config);
        self::assertArrayNotHasKey('responseFormat', $config);
    }

    public function testItGivesUpWhenNoConfigurationIsAccepted(): void
    {
        $unknownField = new JsonMockResponse([
            'error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Unknown name "whatever"'],
        ], ['http_code' => 400]);
        $generator = self::generator([$unknownField, $unknownField, $unknownField]);

        $this->expectException(ImageGenerationFailedException::class);

        $generator->generate(self::request());
    }

    public function testARealProviderErrorIsNotRetried(): void
    {
        $recorder = new RequestRecorder();
        $generator = self::generator([
            new JsonMockResponse(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']], ['http_code' => 429]),
        ], $recorder);

        try {
            $generator->generate(self::request());
            self::fail('An exception was expected.');
        } catch (ImageGenerationFailedException) {
            self::assertSame(1, $recorder->count(), 'A quota error must not trigger a configuration retry.');
        }
    }

    /**
     * The model sometimes answers in words instead of drawing, and its sentence says
     * why. Showing it beats the platform's type error, which names only classes.
     */
    public function testAnAnswerWithoutAnImageQuotesWhatTheModelSaid(): void
    {
        $recorder = new RequestRecorder();
        $generator = self::generator([new JsonMockResponse([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Je ne peux pas reproduire ce visage.']]], 'finishReason' => 'STOP']],
        ])], $recorder);

        try {
            $generator->generate(self::request());
            self::fail('An exception was expected.');
        } catch (ImageGenerationFailedException $exception) {
            self::assertStringContainsString('Je ne peux pas reproduire ce visage.', $exception->getMessage());
            self::assertStringNotContainsString('BinaryResult', $exception->getMessage());
            self::assertSame(1, $recorder->count(), 'A text answer is not a spelling problem: no other style is tried.');
        }
    }

    /**
     * An explanation of our own must reach the grid as written, not wrapped a second
     * time inside "Gemini could not generate the image: …".
     */
    public function testOurOwnExplanationIsNotWrappedAgain(): void
    {
        $generator = self::generator([new JsonMockResponse([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Désolé.']]], 'finishReason' => 'STOP']],
        ])]);

        try {
            $generator->generate(self::request());
            self::fail('An exception was expected.');
        } catch (ImageGenerationFailedException $exception) {
            self::assertStringStartsWith('Gemini answered with text', $exception->getMessage());
        }
    }

    private static function request(): ImageRequest
    {
        return new ImageRequest('A 16:9 thumbnail', [self::reference('FRONT', ReferenceAngle::Front)]);
    }

    private static function reference(string $binary, ReferenceAngle $angle): ReferenceImage
    {
        return new ReferenceImage($binary, 'image/png', $angle, 'Photo');
    }

    private static function imageResponse(string $binary): JsonMockResponse
    {
        return new JsonMockResponse([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Here is the thumbnail.'],
                    ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($binary)]],
                ]],
                'finishReason' => 'STOP',
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private static function settings(array $stored = []): Settings
    {
        return new Settings(new InMemorySettingStore($stored), 'gemini-3.1-flash-lite', 'gemini-3.1-flash-image');
    }

    /**
     * @param list<JsonMockResponse> $responses
     */
    private static function generator(array $responses, ?RequestRecorder $recorder = null, ?Settings $settings = null): GeminiImageGenerator
    {
        $recorder ??= new RequestRecorder();

        return new GeminiImageGenerator(
            Factory::createPlatform('test-key', $recorder->client($responses)),
            $settings ?? self::settings(),
            new NullLogger(),
        );
    }
}
