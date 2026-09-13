<?php

declare(strict_types=1);

namespace App\Thumbnail\Entity;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return self::Ready === $this || self::Failed === $this;
    }
}
