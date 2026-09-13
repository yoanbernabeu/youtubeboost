<?php

declare(strict_types=1);

namespace App\YouTube\Api\Dto;

/**
 * Views per `creatorContentType`, YouTube's own classification of a video.
 */
final readonly class ContentTypeBreakdown
{
    public const string SHORTS = 'SHORTS';
    public const string LIVE_STREAM = 'LIVE_STREAM';
    public const string VIDEO_ON_DEMAND = 'VIDEO_ON_DEMAND';

    /**
     * @param array<string, int> $viewsPerType
     */
    public function __construct(private array $viewsPerType)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return [] === array_filter($this->viewsPerType, static fn (int $views): bool => $views > 0);
    }

    /**
     * The content type that accumulated the most views, which is what the video
     * actually is. A video can in theory appear under several types.
     */
    public function dominantType(): ?string
    {
        $best = null;
        $bestViews = 0;
        foreach ($this->viewsPerType as $type => $views) {
            if ($views > $bestViews) {
                $bestViews = $views;
                $best = $type;
            }
        }

        return $best;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->viewsPerType;
    }
}
