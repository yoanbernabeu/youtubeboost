<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * The four normalised signals that make up a relaunch score.
 *
 * Each signal is expressed in the [0, 1] interval where 1 means "strong reason
 * to give this video a new thumbnail".
 */
enum Signal: string
{
    /** The video used to perform better than it does now. */
    case Decline = 'decline';

    /** YouTube still shows the video but viewers no longer click. */
    case LowCtr = 'low_ctr';

    /** YouTube still serves impressions, so a new thumbnail can still be seen. */
    case Impressions = 'impressions';

    /** The video proved it interests people, so it is worth the effort. */
    case Potential = 'potential';

    public function defaultWeight(): float
    {
        return match ($this) {
            self::Decline => 30.0,
            self::LowCtr => 30.0,
            self::Impressions => 15.0,
            self::Potential => 25.0,
        };
    }

    /**
     * The signal without which a score means nothing.
     *
     * Only the decline qualifies. It is the structural one, and it is computed
     * from view counts, which are always available. Requiring the click-through
     * rate as well would mark every video of a fresh instance as unreliable for
     * weeks, until the thumbnail reach reports have accumulated — turning a
     * useful warning into permanent noise.
     */
    public function isEssential(): bool
    {
        return self::Decline === $this;
    }
}
