<?php

declare(strict_types=1);

namespace App\YouTube\Quota;

/**
 * The daily YouTube Data API quota window.
 *
 * Google resets it at midnight Pacific time, so that is the day boundary the
 * counter has to use, not the server's local midnight.
 */
final class QuotaWindow
{
    public const string TIMEZONE = 'America/Los_Angeles';
    public const int DAILY_LIMIT = 10000;

    private function __construct()
    {
    }

    /**
     * The quota day an instant belongs to, as a date at midnight UTC.
     */
    public static function day(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $pacific = $at->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return new \DateTimeImmutable($pacific->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    public static function nextReset(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        return $at->setTimezone($timezone)
            ->modify('+1 day')
            ->setTime(0, 0);
    }
}
