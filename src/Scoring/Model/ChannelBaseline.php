<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * Channel-wide yardsticks a single video is compared against.
 *
 * Every value is nullable: a channel without impression data simply neutralises
 * the signals that depend on it.
 */
final readonly class ChannelBaseline
{
    public function __construct(
        /** Median click-through rate over the recent window, as a percentage. */
        public ?float $medianClickThroughRate = null,
        /** Median number of impressions over the recent window. */
        public ?float $medianImpressions = null,
        /** Median share of the video watched, as a percentage. */
        public ?float $medianAverageViewPercentage = null,
        /** Lifetime view count that represents a top performer on this channel. */
        public ?float $referenceViewCount = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }
}
