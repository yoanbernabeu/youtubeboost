<?php

declare(strict_types=1);

namespace App\Job\Entity;

enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return self::Succeeded === $this || self::Failed === $this;
    }
}
