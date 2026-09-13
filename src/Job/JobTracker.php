<?php

declare(strict_types=1);

namespace App\Job;

use App\Job\Entity\Job;
use App\Job\Entity\JobType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates and updates {@see Job} rows so the interface can follow background work.
 *
 * Every change is flushed immediately: the point is to be visible from another
 * process while the handler is still running.
 */
final class JobTracker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function create(JobType $type, ?string $subjectId = null): Job
    {
        $job = new Job($type, $subjectId, $this->now());
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    public function start(Job $job, string|TranslatableInterface|null $step = null): void
    {
        $job->start($this->now(), $this->render($step));
        $this->entityManager->flush();
    }

    public function advance(Job $job, int $done, int $total, string|TranslatableInterface|null $step = null): void
    {
        $job->advance($done, $total, $this->render($step));
        $this->entityManager->flush();
    }

    public function succeed(Job $job, string|TranslatableInterface|null $step = null): void
    {
        $job->succeed($this->now(), $this->render($step));
        $this->entityManager->flush();
    }

    public function fail(Job $job, string|TranslatableInterface $message): void
    {
        $job->fail($this->now(), (string) $this->render($message));

        // A failure caused by the database itself leaves the manager closed; the
        // job row then stays "running" rather than hiding the original error.
        if ($this->entityManager->isOpen()) {
            $this->entityManager->flush();
        }
    }

    /**
     * Turns a label into the text stored on the row.
     *
     * A job is written by the worker and read from the interface, possibly much
     * later: what lands in the column is the finished sentence, in the language
     * the application was set to when the work ran.
     */
    public function render(string|TranslatableInterface|null $label): ?string
    {
        if ($label instanceof TranslatableInterface) {
            return $label->trans($this->translator);
        }

        return $label;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
