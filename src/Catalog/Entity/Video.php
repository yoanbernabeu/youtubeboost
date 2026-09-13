<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\VideoRepository;
use App\Scoring\Model\Score;
use App\Scoring\Model\SignalSet;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A video of the connected channel.
 *
 * The YouTube video id is used as the primary key: it is stable, unique, and it
 * saves a lookup on every synchronisation.
 */
#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'video')]
#[ORM\Index(name: 'idx_video_score', columns: ['score'])]
#[ORM\Index(name: 'idx_video_published_at', columns: ['published_at'])]
class Video
{
    /**
     * YouTube identifiers are base64url: letters, digits, underscore and dash.
     * `Requirement::ASCII_SLUG` rejects the underscore and would 500 on a real
     * catalogue.
     */
    public const string ID_PATTERN = '[A-Za-z0-9_-]{1,32}';

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $youtubeId;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column]
    private int $durationSeconds;

    #[ORM\Column(length: 16, enumType: VideoType::class)]
    private VideoType $type = VideoType::Standard;

    #[ORM\Column(length: 16, enumType: TypeSource::class)]
    private TypeSource $typeSource = TypeSource::Unknown;

    #[ORM\Column(length: 16, enumType: Visibility::class)]
    private Visibility $visibility;

    #[ORM\Embedded(class: ThumbnailSet::class, columnPrefix: 'thumbnails_')]
    private ThumbnailSet $thumbnails;

    #[ORM\Column]
    private int $viewCount = 0;

    #[ORM\Column]
    private int $likeCount = 0;

    #[ORM\Column]
    private int $commentCount = 0;

    /**
     * Mirrors `contentDetails.caption`. Measured on 382 videos: it is true only where
     * subtitles were uploaded by hand, and false on every video whose sole track is
     * automatic — so it says nothing about whether a transcript can be obtained.
     */
    #[ORM\Column]
    private bool $captionsAvailable = false;

    /** `snippet.defaultAudioLanguage`, used to pick the right caption track. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $language = null;

    /** Mirrors `contentDetails.hasCustomThumbnail`, only visible to the channel owner. */
    #[ORM\Column(nullable: true)]
    private ?bool $customThumbnail = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $score = 0;

    /** @var array<string, float|null> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $signals = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $scoreReliable = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scoredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAnalyzedAt = null;

    /** Last day for which daily Analytics data was imported. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $statsSyncedUntil = null;

    #[ORM\Column(length: 16, enumType: RelaunchState::class, options: ['default' => 'none'])]
    private RelaunchState $relaunchState = RelaunchState::None;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRelaunchAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function __construct(
        string $youtubeId,
        string $title,
        string $description,
        \DateTimeImmutable $publishedAt,
        int $durationSeconds,
        Visibility $visibility,
        ThumbnailSet $thumbnails,
        ?\DateTimeImmutable $syncedAt = null,
    ) {
        $this->youtubeId = $youtubeId;
        $this->title = $title;
        $this->description = $description;
        $this->publishedAt = $publishedAt;
        $this->durationSeconds = $durationSeconds;
        $this->visibility = $visibility;
        $this->thumbnails = $thumbnails;
        $this->syncedAt = $syncedAt ?? new \DateTimeImmutable();
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function getType(): VideoType
    {
        return $this->type;
    }

    public function getTypeSource(): TypeSource
    {
        return $this->typeSource;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function getThumbnails(): ThumbnailSet
    {
        return $this->thumbnails;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getLikeCount(): int
    {
        return $this->likeCount;
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function hasCustomThumbnail(): ?bool
    {
        return $this->customThumbnail;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getSignals(): SignalSet
    {
        return SignalSet::fromArray($this->signals);
    }

    public function isScoreReliable(): bool
    {
        return $this->scoreReliable;
    }

    public function getScoredAt(): ?\DateTimeImmutable
    {
        return $this->scoredAt;
    }

    public function getLastAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->lastAnalyzedAt;
    }

    public function getStatsSyncedUntil(): ?\DateTimeImmutable
    {
        return $this->statsSyncedUntil;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getRelaunchState(): RelaunchState
    {
        return $this->relaunchState;
    }

    public function getLastRelaunchAt(): ?\DateTimeImmutable
    {
        return $this->lastRelaunchAt;
    }

    public function ageInDays(\DateTimeImmutable $now): int
    {
        $days = (int) $this->publishedAt->setTime(0, 0)->diff($now->setTime(0, 0))->format('%r%a');

        return max(0, $days);
    }

    /**
     * A video is worth relaunching only if it is a regular, visible video.
     */
    public function isRelaunchable(): bool
    {
        return VideoType::Standard === $this->type && Visibility::Private !== $this->visibility;
    }

    public function updateMetadata(
        string $title,
        string $description,
        \DateTimeImmutable $publishedAt,
        int $durationSeconds,
        Visibility $visibility,
        ThumbnailSet $thumbnails,
        \DateTimeImmutable $syncedAt,
    ): void {
        $this->title = $title;
        $this->description = $description;
        $this->publishedAt = $publishedAt;
        $this->durationSeconds = $durationSeconds;
        $this->visibility = $visibility;
        $this->thumbnails = $thumbnails;
        $this->syncedAt = $syncedAt;
    }

    public function updateStatistics(int $viewCount, int $likeCount, int $commentCount): void
    {
        $this->viewCount = $viewCount;
        $this->likeCount = $likeCount;
        $this->commentCount = $commentCount;
    }

    public function updateContentFlags(bool $captionsAvailable, ?bool $customThumbnail, ?string $language = null): void
    {
        $this->captionsAvailable = $captionsAvailable;
        $this->customThumbnail = $customThumbnail;
        $this->language = null === $language ? $this->language : mb_substr($language, 0, 16);
    }

    public function changeVisibility(Visibility $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function replaceThumbnails(ThumbnailSet $thumbnails): void
    {
        $this->thumbnails = $thumbnails;
    }

    /**
     * Records an automatic classification, unless a better source already spoke.
     */
    public function detectType(VideoType $type, TypeSource $source): void
    {
        if (TypeSource::Manual === $source) {
            throw new \InvalidArgumentException('Use forceType() to set a manual classification.');
        }

        if (!$source->outranks($this->typeSource)) {
            return;
        }

        $this->type = $type;
        $this->typeSource = $source;
    }

    /**
     * Lets the creator fix a misclassified video by hand, for good.
     */
    public function forceType(VideoType $type): void
    {
        $this->type = $type;
        $this->typeSource = TypeSource::Manual;
    }

    public function applyScore(Score $score, \DateTimeImmutable $scoredAt): void
    {
        $this->score = $score->value;
        $this->signals = $score->signals->toArray();
        $this->scoreReliable = $score->isReliable();
        $this->scoredAt = $scoredAt;
    }

    public function markAnalyzed(\DateTimeImmutable $analyzedAt): void
    {
        $this->lastAnalyzedAt = $analyzedAt;
    }

    public function markStatsSyncedUntil(\DateTimeImmutable $day): void
    {
        $this->statsSyncedUntil = $day->setTime(0, 0);
    }

    /**
     * Kept in step by the relaunch module, which owns the real history. Stored on
     * the video so the catalogue listing can filter without a join.
     */
    public function recordRelaunchState(RelaunchState $state, ?\DateTimeImmutable $appliedAt): void
    {
        $this->relaunchState = $state;
        $this->lastRelaunchAt = $appliedAt;
    }
}
