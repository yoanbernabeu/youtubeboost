<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\YouTube\Quota\QuotaSnapshot;
use App\YouTube\Quota\QuotaTracker;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Today's YouTube Data API consumption, shown on every page.
 */
#[AsTwigComponent]
final class QuotaBadge
{
    public function __construct(private readonly QuotaTracker $quota)
    {
    }

    public function getSnapshot(): QuotaSnapshot
    {
        return $this->quota->snapshot();
    }

    /**
     * @return array<string, int>
     */
    public function getBreakdown(): array
    {
        return $this->quota->todayPerEndpoint();
    }
}
