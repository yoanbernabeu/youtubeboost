<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Model\DailyPoint;
use App\Catalog\Repository\DailyStatRepository;
use App\Tests\Factory\VideoFactory;
use App\YouTube\Api\Dto\ReachRow;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(DailyStatRepository::class)]
final class DailyStatRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private DailyStatRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DailyStatRepository::class);
    }

    public function testItWritesAndReadsBackADailySeries(): void
    {
        $video = VideoFactory::createOne();

        $this->repository->upsertAnalytics($video->getYoutubeId(), [
            new DailyPoint(new \DateTimeImmutable('2026-06-01'), 120, null, null, 42.5, 180, 300),
            new DailyPoint(new \DateTimeImmutable('2026-06-02'), 90, null, null, 40.0, 170, 250),
        ]);

        $series = $this->repository->loadSeries($video->getYoutubeId());

        self::assertSame(210, $series->totalViews());
        self::assertSame('2026-06-01', $series->firstDate()?->format('Y-m-d'));
        self::assertSame(42.5, $series->averageViewPercentage(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-01')));
        self::assertSame(180.0, $series->averageViewDuration(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-01')));
    }

    public function testWritingTheSameDayTwiceUpdatesItInsteadOfFailing(): void
    {
        $video = VideoFactory::createOne();
        $day = new \DateTimeImmutable('2026-06-01');

        $this->repository->upsertAnalytics($video->getYoutubeId(), [new DailyPoint($day, 10, null, null, null, null, 0)]);
        $this->repository->upsertAnalytics($video->getYoutubeId(), [new DailyPoint($day, 99, null, null, null, null, 0)]);

        self::assertSame(99, $this->repository->loadSeries($video->getYoutubeId())->totalViews());
    }

    public function testReachRowsFillTheImpressionColumnsWithoutTouchingTheViews(): void
    {
        $video = VideoFactory::createOne();
        $day = new \DateTimeImmutable('2026-06-01');

        $this->repository->upsertAnalytics($video->getYoutubeId(), [new DailyPoint($day, 150, null, null, null, null, 0)]);
        $this->repository->upsertReach([new ReachRow($video->getYoutubeId(), $day, 4200, 3.75)]);

        $series = $this->repository->loadSeries($video->getYoutubeId());

        self::assertSame(150, $series->totalViews(), 'The reach import must not overwrite the view count.');
        self::assertSame(4200, $series->totalImpressions($day, $day));
        self::assertSame(3.75, $series->clickThroughRate($day, $day));
    }

    public function testReachRowsCanArriveBeforeTheAnalyticsRows(): void
    {
        $video = VideoFactory::createOne();
        $day = new \DateTimeImmutable('2026-06-01');

        $this->repository->upsertReach([new ReachRow($video->getYoutubeId(), $day, 4200, 3.75)]);
        $this->repository->upsertAnalytics($video->getYoutubeId(), [new DailyPoint($day, 150, null, null, null, null, 0)]);

        $series = $this->repository->loadSeries($video->getYoutubeId());

        self::assertSame(150, $series->totalViews());
        self::assertSame(4200, $series->totalImpressions($day, $day));
    }

    public function testItWritesMoreRowsThanOneChunk(): void
    {
        $video = VideoFactory::createOne();

        $points = [];
        $day = new \DateTimeImmutable('2024-01-01');
        for ($i = 0; $i < 500; ++$i) {
            $points[] = new DailyPoint($day, 1, null, null, null, null, 0);
            $day = $day->modify('+1 day');
        }

        $this->repository->upsertAnalytics($video->getYoutubeId(), $points);

        self::assertSame(500, $this->repository->loadSeries($video->getYoutubeId())->totalViews());
    }

    public function testTheLastImportedDayIgnoresDaysWithoutViews(): void
    {
        $video = VideoFactory::createOne();

        $this->repository->upsertAnalytics($video->getYoutubeId(), [
            new DailyPoint(new \DateTimeImmutable('2026-06-01'), 10, null, null, null, null, 0),
            new DailyPoint(new \DateTimeImmutable('2026-06-05'), 0, null, null, null, null, 0),
        ]);

        self::assertSame('2026-06-01', $this->repository->lastImportedDay($video->getYoutubeId())?->format('Y-m-d'));
    }

    public function testAVideoWithoutStatsHasNoLastImportedDay(): void
    {
        $video = VideoFactory::createOne();

        self::assertNull($this->repository->lastImportedDay($video->getYoutubeId()));
        self::assertTrue($this->repository->loadSeries($video->getYoutubeId())->isEmpty());
    }

    public function testWritingNothingIsANoop(): void
    {
        $video = VideoFactory::createOne();

        $this->repository->upsertAnalytics($video->getYoutubeId(), []);
        $this->repository->upsertReach([]);

        self::assertTrue($this->repository->loadSeries($video->getYoutubeId())->isEmpty());
    }

    public function testItStreamsSeriesVideoByVideo(): void
    {
        $first = VideoFactory::createOne();
        $second = VideoFactory::createOne();
        $this->repository->upsertAnalytics($first->getYoutubeId(), [new DailyPoint(new \DateTimeImmutable('2026-06-01'), 5, null, null, null, null, 0)]);
        $this->repository->upsertAnalytics($second->getYoutubeId(), [new DailyPoint(new \DateTimeImmutable('2026-06-01'), 7, null, null, null, null, 0)]);

        $totals = [];
        foreach ($this->repository->streamSeries([$first->getYoutubeId(), $second->getYoutubeId()]) as $videoId => $series) {
            $totals[$videoId] = $series->totalViews();
        }

        self::assertSame([$first->getYoutubeId() => 5, $second->getYoutubeId() => 7], $totals);
    }

    public function testDeletingAVideoStatsRemovesThemAll(): void
    {
        $video = VideoFactory::createOne();
        $this->repository->upsertAnalytics($video->getYoutubeId(), [new DailyPoint(new \DateTimeImmutable('2026-06-01'), 5, null, null, null, null, 0)]);

        $this->repository->deleteForVideos([$video->getYoutubeId()]);

        self::assertTrue($this->repository->loadSeries($video->getYoutubeId())->isEmpty());
    }
}
