<?php

declare(strict_types=1);

namespace App\YouTube\Quota;

/**
 * What the interface needs to display about today's quota consumption.
 */
final readonly class QuotaSnapshot
{
    /** Share of the daily quota above which the interface warns the creator. */
    private const float WARNING_RATIO = 0.8;

    public int $limit;

    public function __construct(
        public int $used,
        public \DateTimeImmutable $resetsAt,
    ) {
        $this->limit = QuotaWindow::DAILY_LIMIT;
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function usedPercent(): int
    {
        return min(100, (int) round(100 * $this->used / $this->limit));
    }

    public function isNearLimit(): bool
    {
        return $this->used >= (int) ceil($this->limit * self::WARNING_RATIO);
    }

    public function isExhausted(): bool
    {
        return 0 === $this->remaining();
    }

    public function canAfford(int $cost): bool
    {
        return $cost <= $this->remaining();
    }
}
