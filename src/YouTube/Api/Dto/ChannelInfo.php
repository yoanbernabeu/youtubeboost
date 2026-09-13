<?php

declare(strict_types=1);

namespace App\YouTube\Api\Dto;

final readonly class ChannelInfo
{
    public function __construct(
        public string $youtubeId,
        public string $title,
        public string $uploadsPlaylistId,
        public ?string $thumbnailUrl,
        public int $videoCount,
    ) {
    }
}
