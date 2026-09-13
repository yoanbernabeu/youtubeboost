<?php

declare(strict_types=1);

namespace App\YouTube\Api\Dto;

final readonly class CaptionTrack
{
    public const string KIND_ASR = 'asr';
    public const string KIND_STANDARD = 'standard';

    public function __construct(
        public string $id,
        public string $language,
        /** Lower-cased `snippet.trackKind`: the API is inconsistent about its casing. */
        public string $trackKind,
        public string $name,
        public bool $isDraft,
        public ?\DateTimeImmutable $lastUpdated,
    ) {
    }

    public function isAutomatic(): bool
    {
        return self::KIND_ASR === $this->trackKind;
    }

    /**
     * Whether the language matches, ignoring the regional part (`fr` vs `fr-FR`).
     */
    public function matchesLanguage(string $language): bool
    {
        $normalise = static fn (string $value): string => strtolower(explode('-', $value)[0]);

        return $normalise($this->language) === $normalise($language);
    }
}
