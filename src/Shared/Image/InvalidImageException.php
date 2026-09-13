<?php

declare(strict_types=1);

namespace App\Shared\Image;

/**
 * Never shown as such: every caller wraps it in a message of its own, so the
 * wording here only ever reaches the log, and stays in the language of the code.
 */
final class InvalidImageException extends \RuntimeException
{
    public static function unreadable(): self
    {
        return new self('The given content is not a usable image.');
    }
}
