<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use Symfony\Component\Translation\TranslatableMessage;

final readonly class SyncReport
{
    public function __construct(
        public \DateTimeImmutable $referenceDate,
        public VideoImportResult $videos,
        public StatsImportResult $stats,
        public int $reachRowsImported,
        public int $videosScored,
    ) {
    }

    /**
     * One-line summary shown on the job once it is over.
     */
    public function summary(): TranslatableMessage
    {
        return new TranslatableMessage('sync.report.summary', [
            '%total%' => $this->videos->total(),
            '%created%' => $this->videos->created,
            '%scored%' => $this->videosScored,
            '%date%' => $this->referenceDate->format('d/m/Y'),
        ]);
    }
}
