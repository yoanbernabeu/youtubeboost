<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * Everything a signal calculator needs besides the video itself.
 */
final readonly class ScoringContext
{
    public function __construct(
        public ChannelBaseline $baseline,
        public ScoringParameters $parameters,
        /** Last day for which Analytics data is available, channel-wide. */
        public \DateTimeImmutable $referenceDate,
    ) {
    }

    public function windowStart(): \DateTimeImmutable
    {
        return $this->parameters->windowStart($this->referenceDate);
    }

    public function windowEnd(): \DateTimeImmutable
    {
        return $this->referenceDate->setTime(0, 0);
    }
}
