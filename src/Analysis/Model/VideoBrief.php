<?php

declare(strict_types=1);

namespace App\Analysis\Model;

use App\Settings\Entity\ReferenceAngle;

/**
 * Everything the language model is told about a video.
 */
final readonly class VideoBrief
{
    /**
     * @param list<ReferenceAngle> $availableAngles angles actually covered by the reference photos
     */
    public function __construct(
        public string $channelTitle,
        public string $title,
        public string $description,
        public \DateTimeImmutable $publishedAt,
        public int $durationSeconds,
        public ?string $transcript,
        public string $styleGuidelines,
        public string $statsSummary,
        public array $availableAngles,
    ) {
    }

    public function hasTranscript(): bool
    {
        return null !== $this->transcript && '' !== trim($this->transcript);
    }
}
