<?php

declare(strict_types=1);

namespace App\Shared\Storage;

/**
 * Never shown as such: callers either log it or answer a 404, so the wording
 * here only ever reaches the log, and stays in the language of the code.
 */
final class StorageFailedException extends \RuntimeException
{
    public static function cannotWrite(string $path, \Throwable $previous): self
    {
        return new self(\sprintf('Cannot write the file "%s".', $path), 0, $previous);
    }

    public static function cannotRead(string $path, \Throwable $previous): self
    {
        return new self(\sprintf('The file "%s" cannot be found.', $path), 0, $previous);
    }
}
