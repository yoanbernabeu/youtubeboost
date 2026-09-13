<?php

declare(strict_types=1);

namespace App\YouTube\Api\Dto;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Visibility;

/**
 * What `videos.list` tells us about one video, normalised.
 */
final readonly class VideoSnapshot
{
    public function __construct(
        public string $youtubeId,
        public string $title,
        public string $description,
        public \DateTimeImmutable $publishedAt,
        public int $durationSeconds,
        public Visibility $visibility,
        public ThumbnailSet $thumbnails,
        public int $viewCount,
        public int $likeCount,
        public int $commentCount,
        /** True when `liveStreamingDetails` is present, which only livestreams have. */
        public bool $isLiveBroadcast,
        /** Mirrors `contentDetails.caption`: avoids a 50-unit captions.list call. */
        public bool $captionsAvailable,
        /** Only visible to the channel owner; null when absent. */
        public ?bool $customThumbnail,
        /** `snippet.defaultAudioLanguage`, then `snippet.defaultLanguage`. */
        public ?string $language = null,
    ) {
    }
}
