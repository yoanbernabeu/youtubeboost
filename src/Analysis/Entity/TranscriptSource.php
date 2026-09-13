<?php

declare(strict_types=1);

namespace App\Analysis\Entity;

enum TranscriptSource: string
{
    /** Subtitles the creator uploaded: the best case. */
    case Manual = 'manual';

    /** YouTube's automatic speech recognition. */
    case Automatic = 'automatic';

    /**
     * No track could be downloaded. Stored anyway so the 50-unit captions.list
     * call is not paid again on every analysis.
     */
    case Unavailable = 'unavailable';
}
