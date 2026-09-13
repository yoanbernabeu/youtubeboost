<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Message\SynchronizeCatalog;
use App\Job\Entity\Job;
use App\Job\Entity\JobType;
use App\Job\JobTracker;
use App\Job\Repository\JobRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts a synchronisation, or hands back the one already running.
 */
final class SyncLauncher
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobTracker $tracker,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function launch(): Job
    {
        $running = $this->runningJob();
        if (null !== $running) {
            return $running;
        }

        $job = $this->tracker->create(JobType::SyncCatalog);
        $this->bus->dispatch(new SynchronizeCatalog((int) $job->getId()));

        return $job;
    }

    public function runningJob(): ?Job
    {
        return $this->jobs->findRunning([JobType::SyncCatalog])[0] ?? null;
    }

    public function latestJob(): ?Job
    {
        return $this->jobs->findLatestOfType(JobType::SyncCatalog);
    }
}
