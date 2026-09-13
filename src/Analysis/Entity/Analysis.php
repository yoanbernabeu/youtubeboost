<?php

declare(strict_types=1);

namespace App\Analysis\Entity;

use App\Analysis\Model\ThumbnailAngle;
use App\Analysis\Repository\AnalysisRepository;
use App\Catalog\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reading of a video by the language model, with the thumbnail angles it
 * proposed. A video can be analysed again; every analysis is kept.
 */
#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: 'analysis')]
#[ORM\Index(name: 'idx_analysis_video', columns: ['video_id'])]
class Analysis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'youtube_id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $promise;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $angles;

    #[ORM\Column(length: 64)]
    private string $model;

    /** False when the analysis had to rely on the title and description alone. */
    #[ORM\Column]
    private bool $transcriptUsed;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<ThumbnailAngle> $angles
     */
    public function __construct(
        Video $video,
        string $summary,
        string $promise,
        array $angles,
        string $model,
        bool $transcriptUsed,
        \DateTimeImmutable $createdAt,
    ) {
        $this->video = $video;
        $this->summary = $summary;
        $this->promise = $promise;
        $this->angles = array_map(static fn (ThumbnailAngle $angle): array => $angle->toArray(), $angles);
        $this->model = $model;
        $this->transcriptUsed = $transcriptUsed;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getPromise(): string
    {
        return $this->promise;
    }

    /**
     * @return list<ThumbnailAngle>
     */
    public function getAngles(): array
    {
        $angles = [];
        foreach ($this->angles as $index => $angle) {
            $angles[] = ThumbnailAngle::fromArray($index, $angle);
        }

        return $angles;
    }

    public function getAngle(int $index): ?ThumbnailAngle
    {
        $angles = $this->getAngles();

        return $angles[$index] ?? null;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isTranscriptUsed(): bool
    {
        return $this->transcriptUsed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Lets the creator rewrite the prompt of one angle before regenerating it.
     */
    public function replaceAnglePrompt(int $index, string $imagePrompt): void
    {
        $angle = $this->getAngle($index);
        if (null === $angle) {
            throw new \InvalidArgumentException(\sprintf('Angle %d does not exist on this analysis.', $index));
        }

        $this->angles[$index] = $angle->withPrompt($imagePrompt)->toArray();
    }
}
