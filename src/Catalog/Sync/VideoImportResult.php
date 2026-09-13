<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

final readonly class VideoImportResult
{
    /**
     * @param list<string> $videoIds every identifier seen on the channel
     */
    public function __construct(
        public array $videoIds,
        public int $created,
        public int $updated,
        public int $removed,
    ) {
    }

    public function total(): int
    {
        return \count($this->videoIds);
    }
}
