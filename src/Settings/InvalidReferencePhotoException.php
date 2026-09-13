<?php

declare(strict_types=1);

namespace App\Settings;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A reference photo upload the application refuses.
 *
 * The exception message stays a plain English string because it also ends up in
 * the log; {@see $userMessage} carries the wording shown to the creator.
 */
final class InvalidReferencePhotoException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly TranslatableMessage $userMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function uploadFailed(string $name): self
    {
        return new self(
            \sprintf('Uploading "%s" failed.', $name),
            new TranslatableMessage('settings.photos.error.upload_failed', ['%name%' => $name]),
        );
    }

    public static function tooHeavy(string $name, int $maxBytes): self
    {
        $megabytes = intdiv($maxBytes, 1024 * 1024);

        return new self(
            \sprintf('"%s" is heavier than %d MB.', $name, $megabytes),
            new TranslatableMessage('settings.photos.error.too_heavy', ['%name%' => $name, '%size%' => $megabytes]),
        );
    }

    public static function unsupportedType(string $name): self
    {
        return new self(
            \sprintf('"%s" is neither a JPEG, a PNG nor a WebP.', $name),
            new TranslatableMessage('settings.photos.error.unsupported_type', ['%name%' => $name]),
        );
    }

    public static function unreadable(string $name, \Throwable $previous): self
    {
        return new self(
            \sprintf('"%s" is not a readable image.', $name),
            new TranslatableMessage('settings.photos.error.unreadable', ['%name%' => $name]),
            $previous,
        );
    }
}
