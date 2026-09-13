<?php

declare(strict_types=1);

namespace App\Thumbnail\Message;

final readonly class IterateThumbnail
{
    public function __construct(
        public int $proposalId,
        public int $jobId,
    ) {
    }
}
