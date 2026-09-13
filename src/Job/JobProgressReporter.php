<?php

declare(strict_types=1);

namespace App\Job;

use App\Job\Entity\Job;
use App\Shared\Progress\ProgressReporterInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Mirrors the progress of a service onto a {@see Job} row.
 *
 * Writes are throttled: a sync touching 300 videos would otherwise flush on every
 * single one.
 */
final class JobProgressReporter implements ProgressReporterInterface
{
    private int $lastWrittenProgress = -1;

    public function __construct(
        private readonly JobTracker $tracker,
        private readonly Job $job,
    ) {
    }

    public function step(string|TranslatableInterface $label): void
    {
        $this->tracker->advance($this->job, $this->job->getDone(), $this->job->getTotal(), $label);
    }

    public function progress(int $done, int $total, string|TranslatableInterface|null $label = null): void
    {
        $rendered = $this->tracker->render($label);
        $this->job->advance($done, $total, $rendered);

        $isLast = $total > 0 && $done >= $total;
        if ($this->job->getProgress() === $this->lastWrittenProgress && !$isLast) {
            return;
        }

        $this->lastWrittenProgress = $this->job->getProgress();
        $this->tracker->advance($this->job, $done, $total, $rendered);
    }
}
