<?php

declare(strict_types=1);

namespace App\YouTube\Quota;

/**
 * Journalises the quota cost of a Data API call.
 */
interface QuotaRecorderInterface
{
    public function record(YouTubeEndpoint $endpoint, int $calls = 1): void;
}
