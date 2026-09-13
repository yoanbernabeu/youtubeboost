<?php

declare(strict_types=1);

namespace App\Relaunch\Entity;

use App\Catalog\Entity\Video;
use App\Relaunch\Model\PerformanceSnapshot;
use App\Relaunch\Model\Verdict;
use App\Relaunch\Repository\RelaunchRepository;
use App\Thumbnail\Entity\ThumbnailProposal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thumbnail change pushed to YouTube, and what came of it.
 */
#[ORM\Entity(repositoryClass: RelaunchRepository::class)]
#[ORM\Table(name: 'relaunch')]
#[ORM\Index(name: 'idx_relaunch_video', columns: ['video_id'])]
#[ORM\Index(name: 'idx_relaunch_status', columns: ['status'])]
class Relaunch
{
    public const int FIRST_MILESTONE_DAYS = 14;
    public const int FINAL_MILESTONE_DAYS = 28;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'youtube_id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\ManyToOne(targetEntity: ThumbnailProposal::class)]
    #[ORM\JoinColumn(name: 'proposal_id', nullable: true, onDelete: 'SET NULL')]
    private ?ThumbnailProposal $proposal;

    /** Path of the thumbnail that was replaced, inside the archive storage. */
    #[ORM\Column(length: 255)]
    private string $archivedPath;

    #[ORM\Column(length: 64)]
    private string $archivedMimeType;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $previousThumbnailUrl;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appliedImagePath;

    #[ORM\Column(length: 16, enumType: RelaunchStatus::class)]
    private RelaunchStatus $status = RelaunchStatus::Tracking;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshotBefore;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $snapshotAt14 = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $snapshotAt28 = null;

    #[ORM\Column(length: 16, enumType: Verdict::class, nullable: true)]
    private ?Verdict $verdictAt14 = null;

    #[ORM\Column(length: 16, enumType: Verdict::class, nullable: true)]
    private ?Verdict $verdictAt28 = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $appliedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revertedAt = null;

    public function __construct(
        Video $video,
        ?ThumbnailProposal $proposal,
        string $archivedPath,
        string $archivedMimeType,
        ?string $previousThumbnailUrl,
        ?string $appliedImagePath,
        PerformanceSnapshot $snapshotBefore,
        \DateTimeImmutable $appliedAt,
    ) {
        $this->video = $video;
        $this->proposal = $proposal;
        $this->archivedPath = $archivedPath;
        $this->archivedMimeType = $archivedMimeType;
        $this->previousThumbnailUrl = $previousThumbnailUrl;
        $this->appliedImagePath = $appliedImagePath;
        $this->snapshotBefore = $snapshotBefore->toArray();
        $this->appliedAt = $appliedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getProposal(): ?ThumbnailProposal
    {
        return $this->proposal;
    }

    public function getArchivedPath(): string
    {
        return $this->archivedPath;
    }

    public function getArchivedMimeType(): string
    {
        return $this->archivedMimeType;
    }

    public function getPreviousThumbnailUrl(): ?string
    {
        return $this->previousThumbnailUrl;
    }

    public function getAppliedImagePath(): ?string
    {
        return $this->appliedImagePath;
    }

    public function getStatus(): RelaunchStatus
    {
        return $this->status;
    }

    public function getSnapshotBefore(): PerformanceSnapshot
    {
        return PerformanceSnapshot::fromArray($this->snapshotBefore);
    }

    public function getSnapshotAt14(): ?PerformanceSnapshot
    {
        return null === $this->snapshotAt14 ? null : PerformanceSnapshot::fromArray($this->snapshotAt14);
    }

    public function getSnapshotAt28(): ?PerformanceSnapshot
    {
        return null === $this->snapshotAt28 ? null : PerformanceSnapshot::fromArray($this->snapshotAt28);
    }

    public function getVerdictAt14(): ?Verdict
    {
        return $this->verdictAt14;
    }

    public function getVerdictAt28(): ?Verdict
    {
        return $this->verdictAt28;
    }

    /**
     * The verdict to show: the final one when it exists, the provisional one otherwise.
     */
    public function currentVerdict(): ?Verdict
    {
        return $this->verdictAt28 ?? $this->verdictAt14;
    }

    public function isVerdictFinal(): bool
    {
        return null !== $this->verdictAt28;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getRevertedAt(): ?\DateTimeImmutable
    {
        return $this->revertedAt;
    }

    public function isReverted(): bool
    {
        return RelaunchStatus::Reverted === $this->status;
    }

    public function canBeReverted(): bool
    {
        return RelaunchStatus::Reverted !== $this->status;
    }

    /**
     * How many whole days are left before the first milestone can be read.
     *
     * Templates must not compute this themselves: Twig's `date()` returns a mutable
     * `DateTime`, and the page has no business deciding what "now" is anyway.
     */
    public function daysUntilFirstMilestone(\DateTimeImmutable $now): int
    {
        return max(0, self::FIRST_MILESTONE_DAYS - $this->daysSinceApplied($now));
    }

    /**
     * The last day of data a milestone needs before it can be read.
     */
    public function milestoneEnd(int $milestoneDays): \DateTimeImmutable
    {
        return $this->appliedAt->setTime(0, 0)->modify(\sprintf('+%d days', $milestoneDays));
    }

    public function isMilestoneCovered(int $milestoneDays, \DateTimeImmutable $referenceDate): bool
    {
        return $referenceDate->setTime(0, 0) >= $this->milestoneEnd($milestoneDays);
    }

    public function recordMilestone(int $milestoneDays, PerformanceSnapshot $snapshot, Verdict $verdict): void
    {
        if (self::FIRST_MILESTONE_DAYS === $milestoneDays) {
            $this->snapshotAt14 = $snapshot->toArray();
            $this->verdictAt14 = $verdict;

            return;
        }

        if (self::FINAL_MILESTONE_DAYS === $milestoneDays) {
            $this->snapshotAt28 = $snapshot->toArray();
            $this->verdictAt28 = $verdict;
            if (RelaunchStatus::Reverted !== $this->status) {
                $this->status = RelaunchStatus::Completed;
            }

            return;
        }

        throw new \InvalidArgumentException(\sprintf('Unknown milestone of %d days.', $milestoneDays));
    }

    public function markReverted(\DateTimeImmutable $at): void
    {
        $this->status = RelaunchStatus::Reverted;
        $this->revertedAt = $at;
    }

    private function daysSinceApplied(\DateTimeImmutable $now): int
    {
        return max(0, (int) $this->appliedAt->setTime(0, 0)->diff($now->setTime(0, 0))->format('%r%a'));
    }
}
