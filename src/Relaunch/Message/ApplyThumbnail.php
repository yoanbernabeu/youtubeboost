<?php

declare(strict_types=1);

namespace App\Relaunch\Message;

final readonly class ApplyThumbnail
{
    public function __construct(
        public int $proposalId,
        public int $jobId,
    ) {
    }
}
