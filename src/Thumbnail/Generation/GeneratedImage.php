<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

final readonly class GeneratedImage
{
    public function __construct(
        public string $binary,
        public string $mimeType,
        public string $model,
    ) {
    }
}
