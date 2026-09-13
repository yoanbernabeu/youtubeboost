<?php

declare(strict_types=1);

namespace App\Analysis\Entity;

use App\Analysis\Repository\TranscriptRepository;
use App\Catalog\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The text of a video, downloaded once and kept for good.
 *
 * `captions.download` costs 200 quota units, so this cache is what makes
 * repeated analyses of the same video affordable.
 */
#[ORM\Entity(repositoryClass: TranscriptRepository::class)]
#[ORM\Table(name: 'transcript')]
class Transcript
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'youtube_id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(length: 16, enumType: TranscriptSource::class)]
    private TranscriptSource $source;

    #[ORM\Column(length: 16)]
    private string $language;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    /** Translation key, not a sentence: the worker writes it outside any locale. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $unavailableReason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    private function __construct(
        Video $video,
        TranscriptSource $source,
        string $language,
        string $text,
        ?string $unavailableReason,
        \DateTimeImmutable $fetchedAt,
    ) {
        $this->video = $video;
        $this->source = $source;
        $this->language = $language;
        $this->text = $text;
        $this->unavailableReason = $unavailableReason;
        $this->fetchedAt = $fetchedAt;
    }

    public static function downloaded(Video $video, TranscriptSource $source, string $language, string $text, \DateTimeImmutable $fetchedAt): self
    {
        return new self($video, $source, $language, $text, null, $fetchedAt);
    }

    public static function unavailable(Video $video, string $reason, \DateTimeImmutable $fetchedAt): self
    {
        return new self($video, TranscriptSource::Unavailable, '', '', mb_substr($reason, 0, 255), $fetchedAt);
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getSource(): TranscriptSource
    {
        return $this->source;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getUnavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function isUsable(): bool
    {
        return TranscriptSource::Unavailable !== $this->source && '' !== trim($this->text);
    }

    public function characterCount(): int
    {
        return mb_strlen($this->text);
    }

    public function excerpt(int $length = 400): string
    {
        return mb_strlen($this->text) <= $length ? $this->text : mb_substr($this->text, 0, $length) . '…';
    }
}
