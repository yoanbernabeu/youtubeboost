<?php

declare(strict_types=1);

namespace App\Catalog\Model;

/**
 * Aggregated figures about one video, for the interface and for the prompts.
 */
final readonly class VideoStatsSummary
{
    public function __construct(
        public int $windowDays,
        public int $lifetimeViews,
        public ?float $viewsPerDay,
        public ?float $bestViewsPerDay,
        public ?int $impressions,
        public ?float $clickThroughRate,
        public ?float $averageViewPercentage,
        public ?float $averageViewDuration,
        public ?\DateTimeImmutable $lastDataDay,
    ) {
    }

    /**
     * Share of its own best period the video still achieves, as a percentage.
     */
    public function retainedShare(): ?float
    {
        if (null === $this->viewsPerDay || null === $this->bestViewsPerDay || $this->bestViewsPerDay <= 0.0) {
            return null;
        }

        return 100 * $this->viewsPerDay / $this->bestViewsPerDay;
    }

    /**
     * Plain recap injected into the analysis prompt.
     *
     * English, and formatted the English way, whatever the interface language:
     * this is data handed to the model alongside instructions written in English,
     * not a sentence the creator ever reads. A French decimal comma here would
     * only give the model one more thing to misparse.
     */
    public function toPromptSummary(): string
    {
        $parts = [\sprintf('%s lifetime views', self::number($this->lifetimeViews))];

        if (null !== $this->viewsPerDay) {
            $parts[] = \sprintf('%s views/day over the last %d days', self::decimal($this->viewsPerDay), $this->windowDays);
        }

        if (null !== $this->bestViewsPerDay) {
            $parts[] = \sprintf('best period at %s views/day', self::decimal($this->bestViewsPerDay));
        }

        if (null !== $this->impressions) {
            $parts[] = \sprintf('%s thumbnail impressions', self::number($this->impressions));
        }

        if (null !== $this->clickThroughRate) {
            $parts[] = \sprintf('%s%% click-through rate', self::decimal($this->clickThroughRate, 2));
        }

        if (null !== $this->averageViewPercentage) {
            $parts[] = \sprintf('%s%% of the video watched on average', self::decimal($this->averageViewPercentage));
        }

        return implode(', ', $parts) . '.';
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }

    private static function decimal(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, '.', ',');
    }
}
