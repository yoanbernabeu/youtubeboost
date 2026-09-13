<?php

declare(strict_types=1);

namespace App\Analysis\Transcript;

use App\Analysis\Entity\TranscriptSource;

final readonly class TranscriptDraft
{
    private function __construct(
        public TranscriptSource $source,
        public string $language,
        public string $text,
        /** Translation key explaining why nothing could be downloaded. */
        public ?string $reason,
    ) {
    }

    public static function downloaded(TranscriptSource $source, string $language, string $text): self
    {
        return new self($source, $language, $text, null);
    }

    /**
     * @param string $reason translation key explaining the failure
     */
    public static function unavailable(string $reason): self
    {
        return new self(TranscriptSource::Unavailable, '', '', $reason);
    }

    public function isUsable(): bool
    {
        return TranscriptSource::Unavailable !== $this->source;
    }
}
