<?php

declare(strict_types=1);

namespace App\Analysis\Message;

final readonly class AnalyzeVideo
{
    public function __construct(
        public string $videoId,
        public int $jobId,
    ) {
    }
}
