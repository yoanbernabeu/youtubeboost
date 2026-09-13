<?php

declare(strict_types=1);

namespace App\YouTube\Entity;

use App\YouTube\Quota\YouTubeEndpoint;
use App\YouTube\Repository\QuotaUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One journalised YouTube Data API call and what it cost.
 */
#[ORM\Entity(repositoryClass: QuotaUsageRepository::class)]
#[ORM\Table(name: 'quota_usage')]
#[ORM\Index(name: 'idx_quota_usage_day', columns: ['quota_day'])]
class QuotaUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Quota day in Pacific time, which is when Google resets the counter. */
    #[ORM\Column(name: 'quota_day', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $quotaDay;

    #[ORM\Column(length: 32, enumType: YouTubeEndpoint::class)]
    private YouTubeEndpoint $endpoint;

    #[ORM\Column]
    private int $cost;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct(\DateTimeImmutable $quotaDay, YouTubeEndpoint $endpoint, int $cost, \DateTimeImmutable $recordedAt)
    {
        $this->quotaDay = $quotaDay;
        $this->endpoint = $endpoint;
        $this->cost = $cost;
        $this->recordedAt = $recordedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuotaDay(): \DateTimeImmutable
    {
        return $this->quotaDay;
    }

    public function getEndpoint(): YouTubeEndpoint
    {
        return $this->endpoint;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
