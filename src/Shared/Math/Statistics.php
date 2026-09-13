<?php

declare(strict_types=1);

namespace App\Shared\Math;

/**
 * Small statistical helpers shared by the scoring and tracking modules.
 */
final class Statistics
{
    private function __construct()
    {
    }

    /**
     * @param list<float> $values
     */
    public static function median(array $values): ?float
    {
        return self::percentile($values, 0.5);
    }

    /**
     * Linearly interpolated percentile.
     *
     * @param list<float> $values
     * @param float       $ratio  between 0 and 1
     */
    public static function percentile(array $values, float $ratio): ?float
    {
        if ($ratio < 0.0 || $ratio > 1.0) {
            throw new \InvalidArgumentException('The percentile ratio must be between 0 and 1.');
        }

        if ([] === $values) {
            return null;
        }

        sort($values);
        $position = $ratio * (\count($values) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $values[$lower];
        }

        return $values[$lower] + ($position - $lower) * ($values[$upper] - $values[$lower]);
    }

    public static function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
