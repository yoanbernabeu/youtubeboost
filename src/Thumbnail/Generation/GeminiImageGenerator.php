<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Thumbnail\Exception\ImageGenerationFailedException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates thumbnails with a Gemini image model.
 *
 * Reference photos are sent as inline image parts in the same user message as the
 * prompt, which is what lets the model keep the creator's face recognisable. An
 * iteration simply sends the previous image instead of the references.
 */
#[AsAlias(ImageGeneratorInterface::class)]
final class GeminiImageGenerator implements ImageGeneratorInterface
{
    /** Wordings Google uses when a `generationConfig` field is not recognised. */
    private const array UNKNOWN_FIELD_MARKERS = ['unknown name', 'cannot find field', 'invalid json payload', 'unknown field'];

    /**
     * Wording Google uses when the field exists but the value is spelled for
     * another style. `imageConfig` takes `16:9`, `responseFormat.image` takes a
     * proto enum name, and sending one spelling to the other is a flat refusal.
     */
    private const string REJECTED_VALUE_MARKER = 'invalid value at';

    /**
     * Image configuration fields, to tell a rejected ratio from a rejected
     * `responseModalities`: the first means try another style, the second is a bug.
     */
    private const array IMAGE_CONFIG_FIELDS = ['response_format', 'image_config', 'aspect_ratio', 'image_size'];

    public function __construct(
        #[Autowire(service: 'ai.platform.gemini')]
        private readonly PlatformInterface $platform,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function generate(ImageRequest $request): GeneratedImage
    {
        $model = $this->settings->getImageModel();
        $messages = new MessageBag(Message::ofUser(...$this->contentOf($request)));

        $lastFailure = null;

        foreach ($this->styleCandidates() as $style) {
            try {
                $binary = $this->invoke($model, $messages, $style);
            } catch (ImageGenerationFailedException $exception) {
                // Already explained, and not a spelling the next style would fix.
                throw $exception;
            } catch (\Throwable $exception) {
                $lastFailure = $exception;

                if (!$this->looksLikeRejectedConfiguration($exception)) {
                    throw ImageGenerationFailedException::providerRefused($exception);
                }

                $this->logger->notice('Gemini refused an image configuration style, trying the next one.', [
                    'style' => $style->value,
                    'exception' => $exception,
                ]);
                continue;
            }

            $this->rememberStyle($style);

            return new GeneratedImage($binary, 'image/png', $model);
        }

        throw ImageGenerationFailedException::providerRefused($lastFailure ?? new \RuntimeException('No image configuration was accepted.'));
    }

    /**
     * @param non-empty-string $model
     */
    private function invoke(string $model, MessageBag $messages, ImageConfigStyle $style): string
    {
        $options = array_merge(
            ['responseModalities' => ['TEXT', 'IMAGE']],
            $style->toOptions(ImageShape::youtubeThumbnail()),
        );

        $result = $this->platform->invoke($model, $messages, $options);

        try {
            $binary = $result->asBinary();
        } catch (UnexpectedResultTypeException $exception) {
            // The answer carries no image part at all, only words.
            throw ImageGenerationFailedException::textInsteadOfImage(self::answerIn($result), $exception);
        }

        if ('' === $binary) {
            throw ImageGenerationFailedException::noImageReturned();
        }

        return $binary;
    }

    /**
     * What the model said instead of drawing, if it can still be read.
     *
     * The conversion is memoised, so asking for the text costs no second request.
     */
    private static function answerIn(DeferredResult $result): string
    {
        try {
            return $result->asText();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<ContentInterface>
     */
    private function contentOf(ImageRequest $request): array
    {
        $content = [new Text($request->prompt)];

        if ($request->isIteration()) {
            $previous = $request->previousImage;
            \assert(null !== $previous);
            $content[] = new Image($previous->binary, $previous->mimeType);

            return $content;
        }

        if ([] === $request->references) {
            throw ImageGenerationFailedException::noReferencePhoto();
        }

        foreach ($request->references as $reference) {
            $content[] = new Image($reference->binary, $reference->mimeType);
        }

        return $content;
    }

    /**
     * The style that already worked first, then the others in declaration order.
     *
     * @return list<ImageConfigStyle>
     */
    private function styleCandidates(): array
    {
        $all = ImageConfigStyle::cases();

        $known = ImageConfigStyle::tryFrom($this->settings->getString(SettingKey::GeminiImageConfigStyle));
        if (null === $known) {
            return $all;
        }

        return [$known, ...array_values(array_filter($all, static fn (ImageConfigStyle $s): bool => $s !== $known))];
    }

    private function rememberStyle(ImageConfigStyle $style): void
    {
        if ($style->value === $this->settings->getString(SettingKey::GeminiImageConfigStyle)) {
            return;
        }

        $this->settings->set(SettingKey::GeminiImageConfigStyle, $style->value);
    }

    /**
     * Whether the refusal is about how the image configuration is spelled, which
     * the next style may get right, rather than a real failure to report.
     */
    private function looksLikeRejectedConfiguration(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (self::UNKNOWN_FIELD_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        if (!str_contains($message, self::REJECTED_VALUE_MARKER)) {
            return false;
        }

        foreach (self::IMAGE_CONFIG_FIELDS as $field) {
            if (str_contains($message, $field)) {
                return true;
            }
        }

        return false;
    }
}
