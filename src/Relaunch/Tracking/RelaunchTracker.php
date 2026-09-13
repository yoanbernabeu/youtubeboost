<?php

declare(strict_types=1);

namespace App\Relaunch\Tracking;

use App\Catalog\Entity\RelaunchState;
use App\Catalog\Repository\DailyStatRepository;
use App\Catalog\Sync\PostSyncStepInterface;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Entity\RelaunchStatus;
use App\Relaunch\Model\Comparison;
use App\Relaunch\Model\VerdictThresholds;
use App\Relaunch\Repository\RelaunchRepository;
use App\Settings\Settings;
use App\Shared\Progress\ProgressReporterInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the 14 and 28 day milestones of every relaunch still being followed.
 *
 * Runs at the end of each synchronisation, which is the only moment new Analytics
 * data is available.
 */
final class RelaunchTracker implements PostSyncStepInterface
{
    public function __construct(
        private readonly RelaunchRepository $relaunches,
        private readonly DailyStatRepository $dailyStats,
        private readonly SnapshotBuilder $snapshots,
        private readonly VerdictEvaluator $evaluator,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function runAfterSync(\DateTimeImmutable $referenceDate, ProgressReporterInterface $reporter): void
    {
        $pending = $this->relaunches->findAwaitingMilestones();
        if ([] === $pending) {
            return;
        }

        $reporter->step('Suivi des relances');
        $thresholds = VerdictThresholds::fromSettings($this->settings);
        $total = \count($pending);

        foreach ($pending as $index => $relaunch) {
            $this->evaluateMilestones($relaunch, $referenceDate, $thresholds);
            $reporter->progress($index + 1, $total, 'Suivi des relances');
        }

        $this->entityManager->flush();
    }

    /**
     * Also callable on a single relaunch, right after it is applied.
     */
    public function evaluateMilestones(Relaunch $relaunch, \DateTimeImmutable $referenceDate, VerdictThresholds $thresholds): void
    {
        $series = $this->dailyStats->loadSeries($relaunch->getVideo()->getYoutubeId());
        $before = $relaunch->getSnapshotBefore();

        foreach ([Relaunch::FIRST_MILESTONE_DAYS, Relaunch::FINAL_MILESTONE_DAYS] as $milestone) {
            if (!$relaunch->isMilestoneCovered($milestone, $referenceDate)) {
                continue;
            }

            if (Relaunch::FIRST_MILESTONE_DAYS === $milestone && null !== $relaunch->getVerdictAt14()) {
                continue;
            }

            if (Relaunch::FINAL_MILESTONE_DAYS === $milestone && null !== $relaunch->getVerdictAt28()) {
                continue;
            }

            $after = $this->snapshots->build(
                $series,
                $relaunch->getAppliedAt()->setTime(0, 0)->modify('+1 day'),
                $relaunch->milestoneEnd($milestone),
            );

            $comparison = new Comparison($before, $after);
            $relaunch->recordMilestone($milestone, $after, $this->evaluator->evaluate($comparison, $thresholds));
        }

        $this->synchronizeVideoState($relaunch);
    }

    /**
     * Keeps the denormalised state on the video in step with the relaunch.
     */
    private function synchronizeVideoState(Relaunch $relaunch): void
    {
        $state = match ($relaunch->getStatus()) {
            RelaunchStatus::Tracking => RelaunchState::Tracking,
            RelaunchStatus::Completed => RelaunchState::Finished,
            RelaunchStatus::Reverted => RelaunchState::Reverted,
        };

        $relaunch->getVideo()->recordRelaunchState($state, $relaunch->getAppliedAt());
    }
}
