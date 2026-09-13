<?php

declare(strict_types=1);

namespace App\Job\Entity;

use App\Job\Repository\JobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progress of one long-running operation, so the interface can follow a
 * Messenger handler without talking to the queue.
 */
#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: 'job')]
#[ORM\Index(name: 'idx_job_type_status', columns: ['type', 'status'])]
class Job
{
    private const int MAX_ERROR_LENGTH = 1000;

    /**
     * Must match the `step` column. A step often carries an error message, and a
     * provider can be arbitrarily verbose: left unbounded, the overflow aborts the
     * flush, closes the entity manager, and the job can no longer even be marked
     * failed — it stays "running" forever and the interface waits on nothing.
     */
    private const int MAX_STEP_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: JobType::class)]
    private JobType $type;

    #[ORM\Column(length: 16, enumType: JobStatus::class)]
    private JobStatus $status = JobStatus::Pending;

    /** What the job works on, typically a YouTube video id. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subjectId;

    #[ORM\Column(options: ['default' => 0])]
    private int $done = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $total = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $progress = 0;

    /** Human readable description of what is happening right now. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $step = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(JobType $type, ?string $subjectId = null, ?\DateTimeImmutable $createdAt = null)
    {
        $this->type = $type;
        $this->subjectId = $subjectId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): JobType
    {
        return $this->type;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getDone(): int
    {
        return $this->done;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function getStep(): ?string
    {
        return $this->step;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function start(\DateTimeImmutable $at, ?string $step = null): void
    {
        $this->status = JobStatus::Running;
        $this->startedAt = $at;
        $this->setStep($step);
    }

    public function advance(int $done, int $total, ?string $step = null): void
    {
        $this->done = max(0, $done);
        $this->total = max(0, $total);
        $this->progress = $this->total > 0 ? min(100, (int) floor(100 * $this->done / $this->total)) : 0;
        $this->setStep($step);
    }

    public function succeed(\DateTimeImmutable $at, ?string $step = null): void
    {
        $this->status = JobStatus::Succeeded;
        $this->progress = 100;
        $this->finishedAt = $at;
        $this->setStep($step);
    }

    public function fail(\DateTimeImmutable $at, string $message): void
    {
        $this->status = JobStatus::Failed;
        $this->finishedAt = $at;
        $this->errorMessage = mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }

    /** A null step keeps the previous one: callers only pass what changed. */
    private function setStep(?string $step): void
    {
        if (null === $step) {
            return;
        }

        $this->step = mb_substr($step, 0, self::MAX_STEP_LENGTH);
    }
}
