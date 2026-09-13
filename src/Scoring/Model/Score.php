<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * A relaunch score between 0 and 100, together with the signals behind it.
 */
final readonly class Score
{
    /**
     * @param int<0, 100> $value
     */
    public function __construct(
        public int $value,
        public SignalSet $signals,
    ) {
    }

    /**
     * A score is only trustworthy when every essential signal has data.
     */
    public function isReliable(): bool
    {
        foreach (Signal::cases() as $signal) {
            if ($signal->isEssential() && !$this->signals->has($signal)) {
                return false;
            }
        }

        return true;
    }
}
