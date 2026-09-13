<?php

declare(strict_types=1);

namespace App\Relaunch\Model;

/**
 * Before and after, side by side, with the ratios the verdict is based on.
 */
final readonly class Comparison
{
    public function __construct(
        public PerformanceSnapshot $before,
        public PerformanceSnapshot $after,
    ) {
    }

    public function viewsRatio(): ?float
    {
        return self::ratio($this->before->viewsPerDay, $this->after->viewsPerDay);
    }

    public function clickThroughRateRatio(): ?float
    {
        return self::ratio($this->before->clickThroughRate, $this->after->clickThroughRate);
    }

    public function impressionsRatio(): ?float
    {
        return self::ratio($this->before->impressionsPerDay, $this->after->impressionsPerDay);
    }

    public function averageViewDurationRatio(): ?float
    {
        return self::ratio($this->before->averageViewDuration, $this->after->averageViewDuration);
    }

    /**
     * Change as a percentage, positive or negative.
     */
    public function viewsVariation(): ?float
    {
        $ratio = $this->viewsRatio();

        return null === $ratio ? null : 100 * ($ratio - 1);
    }

    private static function ratio(?float $before, ?float $after): ?float
    {
        if (null === $before || null === $after || $before <= 0.0) {
            return null;
        }

        return $after / $before;
    }
}
