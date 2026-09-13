<?php

declare(strict_types=1);

namespace App\Scoring\Model;

final readonly class ScoringResult
{
    public function __construct(
        public Score $score,
        public Eligibility $eligibility,
    ) {
    }
}
