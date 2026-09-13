<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * Whether a video is worth scoring at all, and why not when it is not.
 */
final readonly class Eligibility
{
    private function __construct(
        public bool $isEligible,
        public ?IneligibilityReason $reason,
    ) {
    }

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function ineligible(IneligibilityReason $reason): self
    {
        return new self(false, $reason);
    }
}
