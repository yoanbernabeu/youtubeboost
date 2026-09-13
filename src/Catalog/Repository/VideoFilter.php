<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\RelaunchState;

/**
 * The filters and ordering of the catalogue listing.
 */
final readonly class VideoFilter
{
    public const string SORT_SCORE = 'score';
    public const string SORT_RECENT = 'recent';
    public const string SORT_OLDEST = 'oldest';
    public const string SORT_VIEWS = 'views';
    public const string SORT_TITLE = 'title';

    /** @var list<string> */
    public const array SORTS = [self::SORT_SCORE, self::SORT_RECENT, self::SORT_OLDEST, self::SORT_VIEWS, self::SORT_TITLE];

    public function __construct(
        public ?string $search = null,
        public int $minScore = 0,
        public ?int $minAgeDays = null,
        public ?int $maxAgeDays = null,
        public ?RelaunchState $relaunchState = null,
        /** Ineligible videos score zero and are hidden unless asked for. */
        public bool $includeIneligible = false,
        public string $sort = self::SORT_SCORE,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function sortOrFallback(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : self::SORT_SCORE;
    }

    public function isActive(): bool
    {
        return null !== $this->search
            || 0 !== $this->minScore
            || null !== $this->minAgeDays
            || null !== $this->maxAgeDays
            || null !== $this->relaunchState
            || $this->includeIneligible;
    }
}
