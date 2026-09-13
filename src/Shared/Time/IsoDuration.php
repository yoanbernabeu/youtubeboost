<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * Parses the ISO 8601 durations the YouTube Data API returns.
 *
 * Components are omitted when zero (`PT45S`, `PT3M`, `PT1H`), days appear on long
 * videos (`P2DT3H15M30S`) and a livestream in progress answers `P0D`.
 */
final class IsoDuration
{
    private function __construct()
    {
    }

    public static function toSeconds(string $duration): int
    {
        if ('' === $duration) {
            return 0;
        }

        try {
            $interval = new \DateInterval($duration);
        } catch (\Exception) {
            return 0;
        }

        $epoch = new \DateTimeImmutable('@0');

        return $epoch->add($interval)->getTimestamp();
    }

    /**
     * Human readable duration, as shown on a YouTube card.
     */
    public static function format(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return \sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60);
        }

        return \sprintf('%d:%02d', $minutes, $seconds % 60);
    }
}
