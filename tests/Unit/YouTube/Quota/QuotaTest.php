<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\Quota;

use App\YouTube\Quota\QuotaSnapshot;
use App\YouTube\Quota\QuotaWindow;
use App\YouTube\Quota\YouTubeEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuotaWindow::class)]
#[CoversClass(QuotaSnapshot::class)]
#[CoversClass(YouTubeEndpoint::class)]
final class QuotaTest extends TestCase
{
    #[DataProvider('endpointCosts')]
    public function testEveryEndpointKnowsItsCost(YouTubeEndpoint $endpoint, int $expected): void
    {
        self::assertSame($expected, $endpoint->quotaCost());
    }

    /**
     * @return iterable<string, array{YouTubeEndpoint, int}>
     */
    public static function endpointCosts(): iterable
    {
        yield 'channels.list' => [YouTubeEndpoint::ChannelsList, 1];
        yield 'playlistItems.list' => [YouTubeEndpoint::PlaylistItemsList, 1];
        yield 'videos.list' => [YouTubeEndpoint::VideosList, 1];
        yield 'captions.list' => [YouTubeEndpoint::CaptionsList, 50];
        yield 'captions.download' => [YouTubeEndpoint::CaptionsDownload, 200];
        yield 'thumbnails.set' => [YouTubeEndpoint::ThumbnailsSet, 50];
    }

    public function testTheQuotaDayFollowsThePacificTimeZone(): void
    {
        // 2026-06-30 06:00 UTC is still 2026-06-29 in Los Angeles.
        $day = QuotaWindow::day(new \DateTimeImmutable('2026-06-30 06:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-06-29', $day->format('Y-m-d'));
    }

    public function testTheQuotaDayRollsOverAtPacificMidnight(): void
    {
        $day = QuotaWindow::day(new \DateTimeImmutable('2026-06-30 08:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-06-30', $day->format('Y-m-d'));
    }

    public function testTheNextResetIsTheFollowingPacificMidnight(): void
    {
        $reset = QuotaWindow::nextReset(new \DateTimeImmutable('2026-06-30 09:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-07-01 00:00', $reset->setTimezone(new \DateTimeZone(QuotaWindow::TIMEZONE))->format('Y-m-d H:i'));
    }

    public function testASnapshotDerivesEverythingFromTheUsedUnits(): void
    {
        $snapshot = new QuotaSnapshot(2500, new \DateTimeImmutable('2026-07-01 07:00:00'));

        self::assertSame(2500, $snapshot->used);
        self::assertSame(QuotaWindow::DAILY_LIMIT, $snapshot->limit);
        self::assertSame(7500, $snapshot->remaining());
        self::assertSame(25, $snapshot->usedPercent());
        self::assertFalse($snapshot->isNearLimit());
        self::assertFalse($snapshot->isExhausted());
    }

    public function testASnapshotWarnsFromEightyPercent(): void
    {
        self::assertFalse(new QuotaSnapshot(7999, new \DateTimeImmutable())->isNearLimit());
        self::assertTrue(new QuotaSnapshot(8000, new \DateTimeImmutable())->isNearLimit());
    }

    public function testASnapshotNeverReportsNegativeRemainingUnits(): void
    {
        $snapshot = new QuotaSnapshot(12000, new \DateTimeImmutable());

        self::assertSame(0, $snapshot->remaining());
        self::assertSame(100, $snapshot->usedPercent());
        self::assertTrue($snapshot->isExhausted());
    }

    public function testASnapshotKnowsWhetherAnOperationStillFits(): void
    {
        $snapshot = new QuotaSnapshot(9800, new \DateTimeImmutable());

        self::assertTrue($snapshot->canAfford(200));
        self::assertFalse($snapshot->canAfford(201));
    }
}
