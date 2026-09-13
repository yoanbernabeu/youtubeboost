<?php

declare(strict_types=1);

namespace App\Tests\Unit\Relaunch;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\Visibility;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Model\PerformanceSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Relaunch::class)]
final class RelaunchTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function countdownProvider(): iterable
    {
        yield 'the day it was applied' => ['2026-03-01', 14];
        yield 'the same day, later on' => ['2026-03-01 23:59:59', 14];
        yield 'the next morning' => ['2026-03-02 00:00:01', 13];
        yield 'the eve of the milestone' => ['2026-03-14', 1];
        yield 'the milestone itself' => ['2026-03-15', 0];
        yield 'long past the milestone' => ['2026-06-01', 0];
    }

    #[DataProvider('countdownProvider')]
    public function testTheCountdownRunsDownToZeroAndStaysThere(string $now, int $expected): void
    {
        $relaunch = self::appliedOn('2026-03-01 10:30:00');

        self::assertSame($expected, $relaunch->daysUntilFirstMilestone(new \DateTimeImmutable($now)));
    }

    /**
     * A clock that drifted behind the applied date must not produce a countdown
     * longer than the milestone itself.
     */
    public function testAClockInThePastNeverStretchesTheCountdown(): void
    {
        $relaunch = self::appliedOn('2026-03-01');

        self::assertSame(Relaunch::FIRST_MILESTONE_DAYS, $relaunch->daysUntilFirstMilestone(new \DateTimeImmutable('2026-02-20')));
    }

    private static function appliedOn(string $appliedAt): Relaunch
    {
        return new Relaunch(
            new Video(
                'dQw4w9WgXcQ',
                'Titre de la vidéo',
                'Description',
                new \DateTimeImmutable('2026-01-01'),
                933,
                Visibility::Public,
                ThumbnailSet::fromApiPayload([]),
            ),
            null,
            'archive/avant.jpg',
            'image/jpeg',
            null,
            null,
            new PerformanceSnapshot(28, 100.0, 1000.0, 4.0, 180.0, 40.0),
            new \DateTimeImmutable($appliedAt),
        );
    }
}
