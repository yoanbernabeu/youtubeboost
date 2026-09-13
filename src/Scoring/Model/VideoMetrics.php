<?php

declare(strict_types=1);

namespace App\Scoring\Model;

use App\Catalog\Entity\Video;
use App\Catalog\Model\DailyStatSeries;

/**
 * A video and its daily history, the only input a signal calculator needs.
 */
final readonly class VideoMetrics
{
    public function __construct(
        public Video $video,
        public DailyStatSeries $series,
    ) {
    }
}
