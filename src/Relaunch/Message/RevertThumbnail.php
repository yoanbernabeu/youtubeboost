<?php

declare(strict_types=1);

namespace App\Relaunch\Message;

final readonly class RevertThumbnail
{
    public function __construct(
        public int $relaunchId,
        public int $jobId,
    ) {
    }
}
