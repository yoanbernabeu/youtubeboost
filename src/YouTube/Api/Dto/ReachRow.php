<?php

declare(strict_types=1);

namespace App\YouTube\Api\Dto;

/**
 * One day of thumbnail reach for one video.
 */
final readonly class ReachRow
{
    public function __construct(
        public string $videoId,
        public \DateTimeImmutable $date,
        public int $impressions,
        /** Click-through rate as a percentage, to match the rest of the application. */
        public float $clickThroughRate,
    ) {
    }
}
