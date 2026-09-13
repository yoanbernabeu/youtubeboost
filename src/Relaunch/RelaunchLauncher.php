<?php

declare(strict_types=1);

namespace App\Relaunch;

use App\Job\Entity\Job;
use App\Job\Entity\JobType;
use App\Job\JobTracker;
use App\Job\Repository\JobRepository;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Message\ApplyThumbnail;
use App\Relaunch\Message\RevertThumbnail;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\YouTube\Quota\QuotaTracker;
use App\YouTube\Quota\YouTubeEndpoint;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the two write operations of the application, with a quota check first.
 */
final class RelaunchLauncher
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobTracker $tracker,
        private readonly QuotaTracker $quota,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public static function quotaCost(): int
    {
        return YouTubeEndpoint::ThumbnailsSet->quotaCost();
    }

    public function apply(ThumbnailProposal $proposal): Job
    {
        $this->quota->assertCanAfford(self::quotaCost());

        $job = $this->tracker->create(JobType::ApplyThumbnail, $proposal->getVideo()->getYoutubeId());
        $this->bus->dispatch(new ApplyThumbnail((int) $proposal->getId(), (int) $job->getId()));

        return $job;
    }

    public function revert(Relaunch $relaunch): Job
    {
        $this->quota->assertCanAfford(self::quotaCost());

        $job = $this->tracker->create(JobType::RevertThumbnail, $relaunch->getVideo()->getYoutubeId());
        $this->bus->dispatch(new RevertThumbnail((int) $relaunch->getId(), (int) $job->getId()));

        return $job;
    }

    /**
     * @return list<Job>
     */
    public function runningJobsFor(string $videoId): array
    {
        return array_values(array_filter(
            $this->jobs->findRunning([JobType::ApplyThumbnail, JobType::RevertThumbnail]),
            static fn (Job $job): bool => $job->getSubjectId() === $videoId,
        ));
    }
}
