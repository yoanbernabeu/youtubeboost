<?php

declare(strict_types=1);

namespace App\Analysis\Exception;

use App\Shared\Translation\TranslatableThrowable;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class AnalysisFailedException extends \RuntimeException implements TranslatableThrowable
{
    private function __construct(
        string $message,
        private readonly TranslatableInterface $translatable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function modelUnreachable(\Throwable $previous): self
    {
        return new self(
            \sprintf('Gemini could not analyse the video: %s', $previous->getMessage()),
            new TranslatableMessage('analysis.error.model_unreachable', ['%reason%' => $previous->getMessage()]),
            $previous,
        );
    }

    public static function unreadableAnswer(?\Throwable $previous = null): self
    {
        return new self(
            'Gemini\'s answer cannot be used.',
            new TranslatableMessage('analysis.error.unreadable_answer'),
            $previous,
        );
    }

    public static function geminiNotConfigured(): self
    {
        return new self(
            'GEMINI_API_KEY is not set: add it to .env.local, then recreate the containers.',
            new TranslatableMessage('analysis.error.gemini_not_configured'),
        );
    }

    public static function noUsableAngle(): self
    {
        return new self(
            'Gemini came back with no usable thumbnail angle.',
            new TranslatableMessage('analysis.error.no_usable_angle'),
        );
    }

    public function translatableMessage(): TranslatableInterface
    {
        return $this->translatable;
    }
}
