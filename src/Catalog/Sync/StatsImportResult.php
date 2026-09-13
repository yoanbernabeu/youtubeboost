<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

final readonly class StatsImportResult
{
    public function __construct(
        public int $videosSynced,
        public int $daysImported,
        public int $typesConfirmed,
        public int $failures,
    ) {
    }
}
