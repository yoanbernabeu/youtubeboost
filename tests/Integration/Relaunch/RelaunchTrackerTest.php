<?php

declare(strict_types=1);

namespace App\Tests\Integration\Relaunch;

use App\Catalog\Entity\RelaunchState;
use App\Catalog\Entity\Video;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Repository\DailyStatRepository;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Entity\RelaunchStatus;
use App\Relaunch\Model\PerformanceSnapshot;
use App\Relaunch\Model\Verdict;
use App\Relaunch\Repository\RelaunchRepository;
use App\Relaunch\Tracking\RelaunchTracker;
use App\Shared\Progress\NullProgressReporter;
use App\Tests\Factory\VideoFactory;
use App\YouTube\Api\Dto\ReachRow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(RelaunchTracker::class)]
final class RelaunchTrackerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private RelaunchTracker $tracker;
    private DailyStatRepository $dailyStats;
    private RelaunchRepository $relaunches;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tracker = self::getContainer()->get(RelaunchTracker::class);
        $this->dailyStats = self::getContainer()->get(DailyStatRepository::class);
        $this->relaunches = self::getContainer()->get(RelaunchRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testNothingHappensBeforeTheFirstMilestone(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-06-20', viewsAfter: 300);

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        $reloaded = $this->reload($relaunch);
        self::assertNull($reloaded->getVerdictAt14());
        self::assertNull($reloaded->getVerdictAt28());
        self::assertSame(RelaunchStatus::Tracking, $reloaded->getStatus());
    }

    public function testTheFourteenDayVerdictIsProvisional(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-06-01', viewsAfter: 300);

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-16'), new NullProgressReporter());
        $this->entityManager->clear();

        $reloaded = $this->reload($relaunch);
        self::assertSame(Verdict::Successful, $reloaded->getVerdictAt14());
        self::assertNull($reloaded->getVerdictAt28());
        self::assertFalse($reloaded->isVerdictFinal());
        self::assertSame(Verdict::Successful, $reloaded->currentVerdict());
        self::assertSame(RelaunchStatus::Tracking, $reloaded->getStatus());
    }

    public function testTheTwentyEightDayVerdictClosesTheRelaunch(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-05-01', viewsAfter: 300);

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        $reloaded = $this->reload($relaunch);
        self::assertSame(Verdict::Successful, $reloaded->getVerdictAt14());
        self::assertSame(Verdict::Successful, $reloaded->getVerdictAt28());
        self::assertTrue($reloaded->isVerdictFinal());
        self::assertSame(RelaunchStatus::Completed, $reloaded->getStatus());
        self::assertSame(RelaunchState::Finished, $reloaded->getVideo()->getRelaunchState());
    }

    public function testACollapseIsCalledNegativeAndSuggestsRollingBack(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-05-01', viewsAfter: 20);

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        $reloaded = $this->reload($relaunch);
        self::assertSame(Verdict::Negative, $reloaded->getVerdictAt28());
        self::assertTrue($reloaded->currentVerdict()?->suggestsRevert());
    }

    public function testAFlatResultIsCalledNeutral(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-05-01', viewsAfter: 100);

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        self::assertSame(Verdict::Neutral, $this->reload($relaunch)->getVerdictAt28());
    }

    public function testASecondPassDoesNotRewriteASettledVerdict(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-05-01', viewsAfter: 300);

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();
        $snapshot = $this->reload($relaunch)->getSnapshotAt28();

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        self::assertSame($snapshot?->toArray(), $this->reload($relaunch)->getSnapshotAt28()?->toArray());
    }

    public function testARevertedRelaunchKeepsItsStatusButStillGetsItsVerdict(): void
    {
        $relaunch = $this->relaunch(appliedAt: '2026-05-01', viewsAfter: 300);
        $relaunch->markReverted(new \DateTimeImmutable('2026-05-10'));
        $this->entityManager->flush();

        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        $reloaded = $this->reload($relaunch);
        self::assertSame(RelaunchStatus::Reverted, $reloaded->getStatus());
        self::assertNotNull($reloaded->getVerdictAt28());
        self::assertSame(RelaunchState::Reverted, $reloaded->getVideo()->getRelaunchState());
    }

    public function testARelaunchThatIsAlreadySettledIsNotLoadedAgain(): void
    {
        $this->relaunch(appliedAt: '2026-05-01', viewsAfter: 300);
        $this->tracker->runAfterSync(new \DateTimeImmutable('2026-06-30'), new NullProgressReporter());
        $this->entityManager->clear();

        self::assertSame([], $this->relaunches->findAwaitingMilestones());
    }

    private function reload(Relaunch $relaunch): Relaunch
    {
        $reloaded = $this->relaunches->find($relaunch->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function relaunch(string $appliedAt, int $viewsAfter): Relaunch
    {
        /** @var Video $video */
        $video = VideoFactory::createOne(['publishedAt' => new \DateTimeImmutable('2025-01-01')]);

        $applied = new \DateTimeImmutable($appliedAt . ' 12:00:00');

        // 100 views a day before the change, then the requested level after it.
        $points = [];
        $reach = [];
        $day = $applied->setTime(0, 0)->modify('-60 days');
        for ($i = 0; $i < 150; ++$i) {
            $isAfter = $day > $applied->setTime(0, 0);
            $points[] = new DailyPoint($day, $isAfter ? $viewsAfter : 100, null, null, 40.0, 180, 0);
            $reach[] = new ReachRow($video->getYoutubeId(), $day, 1000, 4.0);
            $day = $day->modify('+1 day');
        }
        $this->dailyStats->upsertAnalytics($video->getYoutubeId(), $points);
        $this->dailyStats->upsertReach($reach);

        $relaunch = new Relaunch(
            $video,
            null,
            'archive/before.jpg',
            'image/jpeg',
            'https://i.ytimg.com/vi/x/maxresdefault.jpg',
            'thumbnails/new.png',
            new PerformanceSnapshot(28, 100.0, 1000.0, 4.0, 180.0, 40.0),
            $applied,
        );
        $video->recordRelaunchState(RelaunchState::Tracking, $applied);

        $this->entityManager->persist($relaunch);
        $this->entityManager->flush();

        return $relaunch;
    }
}
