<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\YouTube\Quota\QuotaRecorderInterface;
use App\YouTube\Quota\YouTubeEndpoint;

final class FakeQuotaRecorder implements QuotaRecorderInterface
{
    /** @var list<array{endpoint: YouTubeEndpoint, calls: int}> */
    public array $records = [];

    public function record(YouTubeEndpoint $endpoint, int $calls = 1): void
    {
        $this->records[] = ['endpoint' => $endpoint, 'calls' => $calls];
    }

    public function totalCost(): int
    {
        $total = 0;
        foreach ($this->records as $record) {
            $total += $record['endpoint']->quotaCost() * $record['calls'];
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    public function calledEndpoints(): array
    {
        return array_map(static fn (array $record): string => $record['endpoint']->value, $this->records);
    }
}
