<?php

declare(strict_types=1);

namespace App\Thumbnail\Exception;

use App\Shared\Translation\TranslatableThrowable;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class ImageGenerationFailedException extends \RuntimeException implements TranslatableThrowable
{
    /** Enough of the model's answer to understand it, not enough to flood the grid. */
    private const int MAX_ANSWER_LENGTH = 300;

    private function __construct(
        string $message,
        private readonly TranslatableInterface $translatable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function providerRefused(\Throwable $previous): self
    {
        return new self(
            \sprintf('Gemini could not generate the image: %s', $previous->getMessage()),
            new TranslatableMessage('thumbnail.error.provider_refused', ['%reason%' => $previous->getMessage()]),
            $previous,
        );
    }

    public static function noImageReturned(): self
    {
        return new self(
            'Gemini answered without an image. Reword the instruction or try again.',
            new TranslatableMessage('thumbnail.error.no_image_returned'),
        );
    }

    /**
     * The model answered in words instead of drawing. Its own sentence usually says
     * why — a refused likeness, a prompt it read as a request it will not honour —
     * so it is worth more on screen than the type error the platform raises.
     */
    public static function textInsteadOfImage(string $answer, ?\Throwable $previous = null): self
    {
        $answer = trim($answer);

        if ('' === $answer) {
            return new self(
                'Gemini answered with text instead of an image, with no explanation. Run the generation again.',
                new TranslatableMessage('thumbnail.error.text_instead_of_image_silent'),
                $previous,
            );
        }

        $answer = mb_substr($answer, 0, self::MAX_ANSWER_LENGTH);

        return new self(
            \sprintf('Gemini answered with text instead of an image: "%s"', $answer),
            new TranslatableMessage('thumbnail.error.text_instead_of_image', ['%answer%' => $answer]),
            $previous,
        );
    }

    public static function noReferencePhoto(): self
    {
        return new self(
            'Add at least one reference photo in the settings before generating thumbnails.',
            new TranslatableMessage('thumbnail.error.no_reference_photo'),
        );
    }

    public function translatableMessage(): TranslatableInterface
    {
        return $this->translatable;
    }
}
