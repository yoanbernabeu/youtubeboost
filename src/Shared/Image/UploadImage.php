<?php

declare(strict_types=1);

namespace App\Shared\Image;

/**
 * An image ready to be sent to `thumbnails.set`, with the MIME type to declare.
 */
final readonly class UploadImage
{
    public function __construct(
        public string $binary,
        public string $mimeType,
    ) {
    }

    public function byteSize(): int
    {
        return \strlen($this->binary);
    }
}
