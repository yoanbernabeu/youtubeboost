<?php

declare(strict_types=1);

namespace App\YouTube\Exception;

/**
 * An API call came back with an error we cannot act on automatically.
 */
final class ApiCallFailedException extends YouTubeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $reason = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
