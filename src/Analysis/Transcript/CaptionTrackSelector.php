<?php

declare(strict_types=1);

namespace App\Analysis\Transcript;

use App\YouTube\Api\Dto\CaptionTrack;

/**
 * Orders the caption tracks of a video from most to least desirable.
 *
 * A manual track in the channel language is worth far more than an automatic one:
 * it is punctuated, spelled correctly and free of recognition errors. The automatic
 * track is the usual fallback and downloads fine for the channel owner, so the order
 * is about quality, not about what is permitted.
 */
final class CaptionTrackSelector
{
    /**
     * @param list<CaptionTrack> $tracks
     *
     * @return list<CaptionTrack> candidates, best first
     */
    public function rank(array $tracks, string $preferredLanguage): array
    {
        $usable = array_values(array_filter($tracks, static fn (CaptionTrack $track): bool => !$track->isDraft));

        usort($usable, function (CaptionTrack $a, CaptionTrack $b) use ($preferredLanguage): int {
            return $this->rankOf($a, $preferredLanguage) <=> $this->rankOf($b, $preferredLanguage);
        });

        return $usable;
    }

    private function rankOf(CaptionTrack $track, string $preferredLanguage): int
    {
        $matchesLanguage = $track->matchesLanguage($preferredLanguage);

        return match (true) {
            !$track->isAutomatic() && $matchesLanguage => 0,
            !$track->isAutomatic() => 1,
            $matchesLanguage => 2,
            default => 3,
        };
    }
}
