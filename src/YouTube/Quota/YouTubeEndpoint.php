<?php

declare(strict_types=1);

namespace App\YouTube\Quota;

/**
 * The YouTube Data API endpoints this application calls, with their quota cost.
 *
 * Analytics and Reporting endpoints are deliberately absent: they draw on their
 * own quota, expressed in requests rather than units.
 */
enum YouTubeEndpoint: string
{
    case ChannelsList = 'channels.list';
    case PlaylistItemsList = 'playlistItems.list';
    case VideosList = 'videos.list';
    case CaptionsList = 'captions.list';
    case CaptionsDownload = 'captions.download';
    case ThumbnailsSet = 'thumbnails.set';

    public function quotaCost(): int
    {
        return match ($this) {
            self::ChannelsList, self::PlaylistItemsList, self::VideosList => 1,
            self::CaptionsList, self::ThumbnailsSet => 50,
            self::CaptionsDownload => 200,
        };
    }
}
