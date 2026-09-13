<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

/**
 * Where the video type classification comes from, best first.
 *
 * A less trustworthy source never overwrites a better one.
 */
enum TypeSource: string
{
    case Unknown = 'unknown';
    case Heuristic = 'heuristic';
    case Analytics = 'analytics';
    case Manual = 'manual';

    public function precedence(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Heuristic => 1,
            self::Analytics => 2,
            self::Manual => 3,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->precedence() >= $other->precedence();
    }
}
