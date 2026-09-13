<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scoring;

use App\Catalog\Entity\RelaunchState;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\VideoType;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Repository\DailyStatRepository;
use App\Scoring\Model\Signal;
use App\Scoring\ScoreRecalculator;
use App\Shared\Progress\NullProgressReporter;
use App\Tests\Factory\VideoFactory;
use App\YouTube\Api\Dto\ReachRow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(ScoreRecalculator::class)]
final class ScoreRecalculatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string REFERENCE_DATE = '2026-06-30';

    private ScoreRecalculator $recalculator;
    private DailyStatRepository $dailyStats;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->recalculator = self::getContainer()->get(ScoreRecalculator::class);
        $this->dailyStats = self::getContainer()->get(DailyStatRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testADecliningVideoScoresHigherThanAHealthyOne(): void
    {
        $declining = $this->videoWithHistory('declining', decliningViews: true, clickThroughRate: 1.5);
        $healthy = $this->videoWithHistory('healthy', decliningViews: false, clickThroughRate: 8.0);

        $this->recalculator->recalculate(new \DateTimeImmutable(self::REFERENCE_DATE), new NullProgressReporter());
        $this->entityManager->clear();

        $decliningScore = $this->reload($declining)->getScore();
        $healthyScore = $this->reload($healthy)->getScore();

        self::assertGreaterThan($healthyScore, $decliningScore);
        self::assertGreaterThan(50, $decliningScore);
    }

    public function testTheSignalsAreStoredAlongsideTheScore(): void
    {
        $video = $this->videoWithHistory('signals', decliningViews: true, clickThroughRate: 1.5);
        $this->videoWithHistory('other', decliningViews: false, clickThroughRate: 8.0);

        $this->recalculator->recalculate(new \DateTimeImmutable(self::REFERENCE_DATE), new NullProgressReporter());
        $this->entityManager->clear();

        $signals = $this->reload($video)->getSignals();

        self::assertNotNull($signals->get(Signal::Decline));
        self::assertNotNull($signals->get(Signal::LowCtr));
        self::assertNotNull($signals->get(Signal::Impressions));
        self::assertNotNull($signals->get(Signal::Potential));
        self::assertTrue($this->reload($video)->isScoreReliable());
    }

    public function testAShortIsScoredZeroAndMarkedUnreliable(): void
    {
        $short = $this->videoWithHistory('short', decliningViews: true, clickThroughRate: 1.0);
        $short->forceType(VideoType::Short);
        $this->entityManager->flush();

        $this->recalculator->recalculate(new \DateTimeImmutable(self::REFERENCE_DATE), new NullProgressReporter());
        $this->entityManager->clear();

        self::assertSame(0, $this->reload($short)->getScore());
        self::assertFalse($this->reload($short)->isScoreReliable());
    }

    public function testAVideoUnderTrackingIsLeftAlone(): void
    {
        $video = $this->videoWithHistory('tracking', decliningViews: true, clickThroughRate: 1.0);
        $video->recordRelaunchState(RelaunchState::Tracking, new \DateTimeImmutable('2026-06-20'));
        $this->entityManager->flush();

        $this->recalculator->recalculate(new \DateTimeImmutable(self::REFERENCE_DATE), new NullProgressReporter());
        $this->entityManager->clear();

        self::assertSame(0, $this->reload($video)->getScore());
    }

    public function testAVideoWithoutAnyHistoryScoresZero(): void
    {
        $video = VideoFactory::createOne(['publishedAt' => new \DateTimeImmutable('2024-01-01')]);

        $this->recalculator->recalculate(new \DateTimeImmutable(self::REFERENCE_DATE), new NullProgressReporter());
        $this->entityManager->clear();

        self::assertSame(0, $this->reload($video)->getScore());
        self::assertFalse($this->reload($video)->isScoreReliable());
    }

    public function testTheBaselineIsComputedFromTheRelaunchableVideosOnly(): void
    {
        $this->videoWithHistory('a', decliningViews: false, clickThroughRate: 4.0);
        $short = $this->videoWithHistory('b', decliningViews: false, clickThroughRate: 50.0);
        $short->forceType(VideoType::Short);
        $this->entityManager->flush();

        $baseline = $this->recalculator->baselineFor(new \DateTimeImmutable(self::REFERENCE_DATE));

        self::assertSame(4.0, $baseline->medianClickThroughRate);
    }

    private function reload(Video $video): Video
    {
        $reloaded = self::getContainer()->get(\App\Catalog\Repository\VideoRepository::class)->find($video->getYoutubeId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function videoWithHistory(string $id, bool $decliningViews, float $clickThroughRate): Video
    {
        $video = VideoFactory::createOne([
            'youtubeId' => $id,
            'publishedAt' => new \DateTimeImmutable('2026-01-01'),
        ]);
        $video->updateStatistics(50000, 100, 10);
        $this->entityManager->flush();

        // Views come from the Analytics API, impressions and CTR from the
        // Reporting API: two different imports, as in production.
        $points = [];
        $reach = [];
        $day = new \DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 181; ++$i) {
            $isRecent = $day >= new \DateTimeImmutable('2026-06-03');
            $views = $decliningViews && $isRecent ? 10 : 200;
            $points[] = new DailyPoint($day, $views, null, null, 45.0, 200, 0);
            $reach[] = new ReachRow($video->getYoutubeId(), $day, 900, $clickThroughRate);
            $day = $day->modify('+1 day');
        }

        $this->dailyStats->upsertAnalytics($video->getYoutubeId(), $points);
        $this->dailyStats->upsertReach($reach);

        return $video;
    }
}
