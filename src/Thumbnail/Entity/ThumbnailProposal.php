<?php

declare(strict_types=1);

namespace App\Thumbnail\Entity;

use App\Analysis\Entity\Analysis;
use App\Catalog\Entity\Video;
use App\Settings\Entity\ReferenceAngle;
use App\Thumbnail\Repository\ThumbnailProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One generated thumbnail.
 *
 * A proposal is created empty so the grid can show five placeholders right away,
 * then filled in by its own background message. Iterations hang off their parent,
 * which keeps the whole history of an idea.
 */
#[ORM\Entity(repositoryClass: ThumbnailProposalRepository::class)]
#[ORM\Table(name: 'thumbnail_proposal')]
#[ORM\Index(name: 'idx_proposal_video', columns: ['video_id'])]
#[ORM\Index(name: 'idx_proposal_analysis', columns: ['analysis_id'])]
class ThumbnailProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Analysis::class)]
    #[ORM\JoinColumn(name: 'analysis_id', nullable: false, onDelete: 'CASCADE')]
    private Analysis $analysis;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'youtube_id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column]
    private int $angleIndex;

    #[ORM\Column(length: 64)]
    private string $overlayText;

    #[ORM\Column(length: 16, enumType: ReferenceAngle::class)]
    private ReferenceAngle $referenceAngle;

    #[ORM\Column(type: Types::TEXT)]
    private string $prompt;

    #[ORM\Column(length: 16, enumType: ProposalStatus::class)]
    private ProposalStatus $status = ProposalStatus::Pending;

    /** Path inside the thumbnail storage; null until the image exists. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $iterationInstruction = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct(
        Analysis $analysis,
        int $angleIndex,
        string $overlayText,
        ReferenceAngle $referenceAngle,
        string $prompt,
        \DateTimeImmutable $createdAt,
        ?self $parent = null,
        ?string $iterationInstruction = null,
    ) {
        $this->analysis = $analysis;
        $this->video = $analysis->getVideo();
        $this->angleIndex = $angleIndex;
        $this->overlayText = mb_substr($overlayText, 0, 64);
        $this->referenceAngle = $referenceAngle;
        $this->prompt = $prompt;
        $this->createdAt = $createdAt;
        $this->parent = $parent;
        $this->iterationInstruction = $iterationInstruction;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnalysis(): Analysis
    {
        return $this->analysis;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getAngleIndex(): int
    {
        return $this->angleIndex;
    }

    public function getOverlayText(): string
    {
        return $this->overlayText;
    }

    public function getReferenceAngle(): ReferenceAngle
    {
        return $this->referenceAngle;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getIterationInstruction(): ?string
    {
        return $this->iterationInstruction;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function isIteration(): bool
    {
        return null !== $this->parent;
    }

    public function isReady(): bool
    {
        return ProposalStatus::Ready === $this->status && null !== $this->imagePath;
    }

    public function hasBeenApplied(): bool
    {
        return null !== $this->appliedAt;
    }

    /**
     * How deep in the iteration chain this proposal sits.
     */
    public function generation(): int
    {
        $depth = 0;
        $parent = $this->parent;
        while (null !== $parent) {
            ++$depth;
            $parent = $parent->getParent();
        }

        return $depth;
    }

    public function markGenerating(): void
    {
        $this->status = ProposalStatus::Generating;
        $this->errorMessage = null;
    }

    public function attachImage(string $imagePath, int $byteSize, string $model, \DateTimeImmutable $generatedAt): void
    {
        $this->imagePath = $imagePath;
        $this->byteSize = $byteSize;
        $this->model = $model;
        $this->status = ProposalStatus::Ready;
        $this->errorMessage = null;
        $this->generatedAt = $generatedAt;
    }

    public function markFailed(string $message): void
    {
        $this->status = ProposalStatus::Failed;
        $this->errorMessage = mb_substr($message, 0, 1000);
    }

    public function markApplied(\DateTimeImmutable $appliedAt): void
    {
        $this->appliedAt = $appliedAt;
    }

    public function replacePrompt(string $prompt): void
    {
        $this->prompt = $prompt;
    }
}
