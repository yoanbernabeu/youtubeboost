<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Entity\TypeSource;
use App\Catalog\Entity\VideoType;
use App\YouTube\Api\Dto\ContentTypeBreakdown;
use App\YouTube\Api\Dto\VideoSnapshot;

/**
 * Tells a Short, a livestream and a regular video apart.
 *
 * The Data API exposes no field for this. The Analytics dimension
 * `creatorContentType` is YouTube's own answer and is therefore preferred; the
 * structural heuristic only covers videos with no Analytics data yet.
 *
 * The `#shorts` tag is deliberately ignored: it was never required, most creators
 * stopped using it, and many leave it in long-form descriptions.
 */
final class VideoTypeClassifier
{
    /** A Short has been allowed to run up to three minutes since October 2024. */
    public const int SHORT_MAX_DURATION_SECONDS = 180;

    /**
     * Structural guess, from what `videos.list` already told us for free.
     */
    public function fromSnapshot(VideoSnapshot $snapshot): TypeGuess
    {
        if ($snapshot->isLiveBroadcast) {
            return new TypeGuess(VideoType::Live, TypeSource::Heuristic);
        }

        $type = $snapshot->durationSeconds > 0 && $snapshot->durationSeconds <= self::SHORT_MAX_DURATION_SECONDS
            ? VideoType::Short
            : VideoType::Standard;

        return new TypeGuess($type, TypeSource::Heuristic);
    }

    /**
     * YouTube's own classification, or null when the video has no views yet.
     */
    public function fromBreakdown(ContentTypeBreakdown $breakdown): ?TypeGuess
    {
        $type = match ($breakdown->dominantType()) {
            ContentTypeBreakdown::SHORTS => VideoType::Short,
            ContentTypeBreakdown::LIVE_STREAM => VideoType::Live,
            ContentTypeBreakdown::VIDEO_ON_DEMAND => VideoType::Standard,
            default => null,
        };

        return null === $type ? null : new TypeGuess($type, TypeSource::Analytics);
    }
}
