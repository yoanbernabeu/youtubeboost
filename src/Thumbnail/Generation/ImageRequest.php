<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

/**
 * One image generation, first pass or iteration.
 */
final readonly class ImageRequest
{
    /**
     * @param list<ReferenceImage> $references photos of the creator, in prompt order
     */
    public function __construct(
        public string $prompt,
        public array $references = [],
        /** The image being iterated on, if any. */
        public ?ReferenceImage $previousImage = null,
    ) {
    }

    public function isIteration(): bool
    {
        return null !== $this->previousImage;
    }
}
