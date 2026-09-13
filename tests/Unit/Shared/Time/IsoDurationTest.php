<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Time;

use App\Shared\Time\IsoDuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IsoDuration::class)]
final class IsoDurationTest extends TestCase
{
    #[DataProvider('durations')]
    public function testItConvertsToSeconds(string $iso, int $expected): void
    {
        self::assertSame($expected, IsoDuration::toSeconds($iso));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function durations(): iterable
    {
        yield 'minutes and seconds' => ['PT15M33S', 933];
        yield 'hours' => ['PT1H30M45S', 5445];
        yield 'seconds only' => ['PT45S', 45];
        yield 'minutes only' => ['PT3M', 180];
        yield 'hours only' => ['PT1H', 3600];
        yield 'days' => ['P2DT3H15M30S', 184530];
        yield 'live in progress' => ['P0D', 0];
        yield 'empty' => ['', 0];
        yield 'garbage' => ['not a duration', 0];
    }

    #[DataProvider('formats')]
    public function testItFormatsForDisplay(int $seconds, string $expected): void
    {
        self::assertSame($expected, IsoDuration::format($seconds));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function formats(): iterable
    {
        yield 'short' => [45, '0:45'];
        yield 'minutes' => [933, '15:33'];
        yield 'hours' => [5445, '1:30:45'];
        yield 'negative is clamped' => [-5, '0:00'];
    }
}
