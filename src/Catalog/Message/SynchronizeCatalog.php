<?php

declare(strict_types=1);

namespace App\Catalog\Message;

final readonly class SynchronizeCatalog
{
    public function __construct(public int $jobId)
    {
    }
}
