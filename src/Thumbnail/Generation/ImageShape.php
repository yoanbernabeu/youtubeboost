<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

/**
 * The shape a thumbnail is asked in, carried in both spellings Gemini uses.
 *
 * `generationConfig.imageConfig` takes `16:9` and `1K`, while
 * `generationConfig.responseFormat.image` types the very same two settings as
 * protobuf enums whose names write the digits out in words. Sending one field the
 * other's vocabulary is refused outright, so the two spellings travel together
 * rather than being picked at the call site.
 */
final readonly class ImageShape
{
    private function __construct(
        public string $aspectRatio,
        public string $aspectRatioEnum,
        public string $imageSize,
        public string $imageSizeEnum,
    ) {
    }

    /**
     * 16:9 at 1K, which the image models render as 1376x768 — slightly wider than
     * 16:9 — and `ImageNormalizer` crops to the 1280x720 YouTube expects.
     */
    public static function youtubeThumbnail(): self
    {
        return new self('16:9', 'ASPECT_RATIO_SIXTEEN_BY_NINE', '1K', 'IMAGE_SIZE_ONE_K');
    }
}
