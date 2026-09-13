<?php

declare(strict_types=1);

namespace App\Relaunch\Model;

/**
 * Daily averages over one period, before or after a thumbnail change.
 */
final readonly class PerformanceSnapshot
{
    public function __construct(
        public int $days,
        public float $viewsPerDay,
        public ?float $impressionsPerDay,
        /** Click-through rate as a percentage. */
        public ?float $clickThroughRate,
        public ?float $averageViewDuration,
        public ?float $averageViewPercentage,
    ) {
    }

    public static function empty(int $days = 0): self
    {
        return new self($days, 0.0, null, null, null, null);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $float = static fn (string $key): ?float => is_numeric($data[$key] ?? null) ? (float) $data[$key] : null;

        return new self(
            is_numeric($data['days'] ?? null) ? (int) $data['days'] : 0,
            $float('viewsPerDay') ?? 0.0,
            $float('impressionsPerDay'),
            $float('clickThroughRate'),
            $float('averageViewDuration'),
            $float('averageViewPercentage'),
        );
    }

    /**
     * @return array{days: int, viewsPerDay: float, impressionsPerDay: float|null, clickThroughRate: float|null, averageViewDuration: float|null, averageViewPercentage: float|null}
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'viewsPerDay' => $this->viewsPerDay,
            'impressionsPerDay' => $this->impressionsPerDay,
            'clickThroughRate' => $this->clickThroughRate,
            'averageViewDuration' => $this->averageViewDuration,
            'averageViewPercentage' => $this->averageViewPercentage,
        ];
    }
}
