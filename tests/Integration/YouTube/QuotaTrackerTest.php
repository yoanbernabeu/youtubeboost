<?php

declare(strict_types=1);

namespace App\Tests\Integration\YouTube;

use App\YouTube\Quota\QuotaTracker;
use App\YouTube\Quota\QuotaWindow;
use App\YouTube\Quota\YouTubeEndpoint;
use App\YouTube\Repository\QuotaUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(QuotaTracker::class)]
final class QuotaTrackerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItSumsTheCostOfTheDay(): void
    {
        $tracker = $this->tracker('2026-06-30 18:00:00');

        $tracker->record(YouTubeEndpoint::PlaylistItemsList, 6);
        $tracker->record(YouTubeEndpoint::VideosList, 6);
        $tracker->record(YouTubeEndpoint::CaptionsDownload);

        $snapshot = $tracker->snapshot();

        self::assertSame(212, $snapshot->used);
        self::assertSame(QuotaWindow::DAILY_LIMIT - 212, $snapshot->remaining());
        self::assertFalse($snapshot->isNearLimit());
    }

    public function testItGroupsTheCostPerEndpoint(): void
    {
        $tracker = $this->tracker('2026-06-30 18:00:00');

        $tracker->record(YouTubeEndpoint::VideosList, 3);
        $tracker->record(YouTubeEndpoint::ThumbnailsSet);

        self::assertSame(['thumbnails.set' => 50, 'videos.list' => 3], $tracker->todayPerEndpoint());
    }

    public function testTheCounterFollowsThePacificDayNotTheLocalOne(): void
    {
        // In July, Los Angeles is seven hours behind UTC: 06:00 UTC is still
        // 23:00 the day before, while 08:00 UTC is already the next day.
        $this->tracker('2026-07-01 06:00:00')->record(YouTubeEndpoint::VideosList);

        $morningAfter = $this->tracker('2026-07-01 08:00:00');

        self::assertSame(0, $morningAfter->snapshot()->used, 'The Pacific day has rolled over, the counter must be back to zero.');
    }

    public function testAnOperationBeyondTheRemainingQuotaIsRefused(): void
    {
        $tracker = $this->tracker('2026-06-30 18:00:00');
        for ($i = 0; $i < 49; ++$i) {
            $tracker->record(YouTubeEndpoint::CaptionsDownload);
        }

        self::assertSame(9800, $tracker->snapshot()->used);

        $tracker->assertCanAfford(200);

        $this->expectException(\App\YouTube\Exception\QuotaExhaustedException::class);
        $tracker->assertCanAfford(250);
    }

    public function testRecordingNothingCostsNothing(): void
    {
        $tracker = $this->tracker('2026-06-30 18:00:00');

        $tracker->record(YouTubeEndpoint::VideosList, 0);

        self::assertSame(0, $tracker->snapshot()->used);
    }

    private function tracker(string $now): QuotaTracker
    {
        self::bootKernel();
        $container = self::getContainer();

        return new QuotaTracker(
            $container->get(QuotaUsageRepository::class),
            $container->get(EntityManagerInterface::class),
            new MockClock(new \DateTimeImmutable($now, new \DateTimeZone('UTC'))),
        );
    }
}
