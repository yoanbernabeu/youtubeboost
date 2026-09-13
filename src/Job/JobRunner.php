<?php

declare(strict_types=1);

namespace App\Job;

use App\Job\Repository\JobRepository;
use App\Shared\Progress\ProgressReporterInterface;
use App\Shared\Translation\TranslatableThrowable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Runs the body of a message handler under the supervision of a {@see Entity\Job}.
 *
 * Failures are recorded on the job rather than rethrown: these operations are
 * triggered by an explicit click and cost quota, so replaying them behind the
 * creator's back would be worse than showing the error.
 */
final class JobRunner
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobTracker $tracker,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(ProgressReporterInterface): (string|\Symfony\Contracts\Translation\TranslatableInterface|null) $work returns the summary to display
     */
    public function run(int $jobId, callable $work): void
    {
        $job = $this->jobs->find($jobId);
        if (null === $job) {
            $this->logger->warning('Job has disappeared before it could run.', ['job' => $jobId]);

            return;
        }

        if ($job->isFinished()) {
            return;
        }

        $this->tracker->start($job, new TranslatableMessage('job.step.preparing'));

        try {
            $summary = $work(new JobProgressReporter($this->tracker, $job));
            $this->tracker->succeed($job, $summary);
        } catch (\Throwable $exception) {
            $this->logger->error('Job failed.', ['job' => $jobId, 'type' => $job->getType()->value, 'exception' => $exception]);
            $this->tracker->fail($job, $exception instanceof TranslatableThrowable
                ? $exception->translatableMessage()
                : $exception->getMessage());
        }
    }
}
