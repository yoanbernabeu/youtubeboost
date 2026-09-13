<?php

declare(strict_types=1);

namespace App\YouTube\Api\Dto;

/**
 * One generated Reporting API report, ready to download.
 */
final readonly class ReportRef
{
    public function __construct(
        public string $id,
        public string $jobId,
        public \DateTimeImmutable $startTime,
        public \DateTimeImmutable $endTime,
        public \DateTimeImmutable $createTime,
        public string $downloadUrl,
    ) {
    }
}
