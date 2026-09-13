<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\DailyStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One day of statistics for one video.
 *
 * The pair (video, day) is the primary key so an import can upsert without
 * looking anything up first. Rows are written and read in bulk through
 * {@see DailyStatRepository}; this mapping mainly exists so migrations and the
 * schema stay in sync.
 */
#[ORM\Entity(repositoryClass: DailyStatRepository::class)]
#[ORM\Table(name: 'daily_stat')]
class DailyStat
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'youtube_id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Id]
    #[ORM\Column(name: 'stat_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(options: ['default' => 0])]
    private int $views = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedMinutesWatched = 0;

    #[ORM\Column(nullable: true)]
    private ?int $averageViewDuration = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $averageViewPercentage = null;

    #[ORM\Column(nullable: true)]
    private ?int $impressions = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $clickThroughRate = null;

    public function __construct(Video $video, \DateTimeImmutable $date)
    {
        $this->video = $video;
        $this->date = $date->setTime(0, 0);
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function getEstimatedMinutesWatched(): int
    {
        return $this->estimatedMinutesWatched;
    }

    public function getAverageViewDuration(): ?int
    {
        return $this->averageViewDuration;
    }

    public function getAverageViewPercentage(): ?float
    {
        return $this->averageViewPercentage;
    }

    public function getImpressions(): ?int
    {
        return $this->impressions;
    }

    public function getClickThroughRate(): ?float
    {
        return $this->clickThroughRate;
    }
}
