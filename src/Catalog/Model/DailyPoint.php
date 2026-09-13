<?php

declare(strict_types=1);

namespace App\Catalog\Model;

/**
 * One day of YouTube Analytics data for a single video.
 *
 * Impression-related metrics are nullable: YouTube only exposes them from a
 * certain date on, and only to the channel owner.
 */
final readonly class DailyPoint
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $views,
        public ?int $impressions,
        /** Click-through rate as a percentage, as returned by the Analytics API. */
        public ?float $clickThroughRate,
        /** Share of the video watched on average, as a percentage. */
        public ?float $averageViewPercentage,
        /** Average watch time in seconds. */
        public ?int $averageViewDuration,
        public int $estimatedMinutesWatched,
    ) {
    }
}
