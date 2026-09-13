<?php

declare(strict_types=1);

namespace App\Relaunch\Apply;

final readonly class FetchedThumbnail
{
    public function __construct(
        public string $binary,
        public string $mimeType,
        public string $url,
    ) {
    }

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
