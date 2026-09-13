<?php

declare(strict_types=1);

namespace App\Catalog\Model;

/**
 * Read model over the daily Analytics history of a video.
 *
 * Every window is inclusive on both bounds and only the date part of the bounds
 * is taken into account. Days YouTube did not report are treated as zero views,
 * which is what they mean in practice.
 */
final readonly class DailyStatSeries
{
    private const string DAY_FORMAT = 'Y-m-d';

    /**
     * @param array<non-empty-string, DailyPoint> $points indexed and ordered by day
     */
    private function __construct(private array $points)
    {
    }

    /**
     * @param iterable<DailyPoint> $points
     */
    public static function fromPoints(iterable $points): self
    {
        $indexed = [];
        foreach ($points as $point) {
            $indexed[$point->date->format(self::DAY_FORMAT)] = $point;
        }
        ksort($indexed);

        /** @var array<non-empty-string, DailyPoint> $indexed */
        return new self($indexed);
    }

    public function isEmpty(): bool
    {
        return [] === $this->points;
    }

    public function firstDate(): ?\DateTimeImmutable
    {
        $points = $this->points;
        $first = reset($points);

        return false === $first ? null : $first->date;
    }

    public function lastDate(): ?\DateTimeImmutable
    {
        $points = $this->points;
        $last = end($points);

        return false === $last ? null : $last->date;
    }

    public function totalViews(): int
    {
        $total = 0;
        foreach ($this->points as $point) {
            $total += $point->views;
        }

        return $total;
    }

    public function viewsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $views = 0;
        foreach ($this->window($from, $to) as $point) {
            $views += $point->views;
        }

        return $views;
    }

    /**
     * Average daily views over the window, or null when the window holds no data.
     *
     * The denominator is the full length of the window, not the number of days
     * YouTube reported, so a video that stopped being watched scores low.
     */
    public function averageViewsPerDay(\DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        $points = $this->window($from, $to);
        if ([] === $points) {
            return null;
        }

        $days = self::dayCount($from, $to);

        return $days > 0 ? $this->viewsBetween($from, $to) / $days : null;
    }

    /**
     * Highest average daily views over any window of $windowDays consecutive
     * days starting on or after $notBefore.
     *
     * This is the yardstick the decline signal compares the present to.
     */
    public function bestRollingAverageViews(int $windowDays, \DateTimeImmutable $notBefore): ?float
    {
        if ($windowDays < 1) {
            throw new \InvalidArgumentException('The rolling window must cover at least one day.');
        }

        $start = self::startOfDay($notBefore);
        $last = $this->lastDate();
        if (null === $last) {
            return null;
        }

        $firstWindowStart = max($start, self::startOfDay($this->firstDate() ?? $start));
        if (self::dayCount($firstWindowStart, $last) < $windowDays) {
            return null;
        }

        $best = null;
        $windowStart = $firstWindowStart;
        while (self::dayCount($windowStart, $last) >= $windowDays) {
            $windowEnd = $windowStart->modify(\sprintf('+%d days', $windowDays - 1));
            $average = $this->viewsBetween($windowStart, $windowEnd) / $windowDays;
            if (null === $best || $average > $best) {
                $best = $average;
            }
            $windowStart = $windowStart->modify('+1 day');
        }

        return $best;
    }

    public function totalImpressions(\DateTimeImmutable $from, \DateTimeImmutable $to): ?int
    {
        $total = null;
        foreach ($this->window($from, $to) as $point) {
            if (null !== $point->impressions) {
                $total = ($total ?? 0) + $point->impressions;
            }
        }

        return $total;
    }

    /**
     * Impression-weighted click-through rate, as a percentage.
     */
    public function clickThroughRate(\DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        $clicks = 0.0;
        $impressions = 0;
        foreach ($this->window($from, $to) as $point) {
            if (null === $point->impressions || null === $point->clickThroughRate || $point->impressions <= 0) {
                continue;
            }
            $clicks += $point->impressions * $point->clickThroughRate;
            $impressions += $point->impressions;
        }

        return $impressions > 0 ? $clicks / $impressions : null;
    }

    /**
     * View-weighted average share of the video watched, as a percentage.
     */
    public function averageViewPercentage(\DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        return $this->viewWeightedAverage($from, $to, static fn (DailyPoint $p): ?float => $p->averageViewPercentage);
    }

    /**
     * View-weighted average watch time, in seconds.
     */
    public function averageViewDuration(\DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        return $this->viewWeightedAverage($from, $to, static fn (DailyPoint $p): ?float => null === $p->averageViewDuration ? null : (float) $p->averageViewDuration);
    }

    /**
     * @param callable(DailyPoint): ?float $metric
     */
    private function viewWeightedAverage(\DateTimeImmutable $from, \DateTimeImmutable $to, callable $metric): ?float
    {
        $weighted = 0.0;
        $views = 0;
        foreach ($this->window($from, $to) as $point) {
            $value = $metric($point);
            if (null === $value || $point->views <= 0) {
                continue;
            }
            $weighted += $point->views * $value;
            $views += $point->views;
        }

        return $views > 0 ? $weighted / $views : null;
    }

    /**
     * @return list<DailyPoint>
     */
    private function window(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $start = self::startOfDay($from)->format(self::DAY_FORMAT);
        $end = self::startOfDay($to)->format(self::DAY_FORMAT);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $window = [];
        foreach ($this->points as $day => $point) {
            if ($day >= $start && $day <= $end) {
                $window[] = $point;
            }
        }

        return $window;
    }

    private static function startOfDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0);
    }

    private static function dayCount(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = (int) self::startOfDay($from)->diff(self::startOfDay($to))->format('%r%a');

        return $days + 1;
    }
}
