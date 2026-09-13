<?php

declare(strict_types=1);

namespace App\YouTube\Entity;

use App\YouTube\Repository\ChannelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The single connected YouTube channel.
 *
 * The application is deliberately mono-channel, so at most one row exists.
 * Tokens are stored already encrypted; this entity never sees them in clear.
 */
#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ORM\Table(name: 'channel')]
class Channel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $youtubeId;

    #[ORM\Column(length: 255)]
    private string $title;

    /** The "uploads" playlist, which lists every video of the channel. */
    #[ORM\Column(length: 64)]
    private string $uploadsPlaylistId;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedRefreshToken;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedAccessToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $accessTokenExpiresAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $scopes = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $connectedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** Last day covered by the imported Analytics data, channel-wide. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analyticsAvailableUntil = null;

    /** Identifier of the Reporting API job that produces thumbnail impressions. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $reportingJobId = null;

    /** Last reporting CSV already imported, so it is never downloaded twice. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $lastImportedReportId = null;

    /**
     * Set when Google refuses the refresh token, which happens on its own after
     * seven days if the OAuth application was left in "Testing" status.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $reconnectionRequired = false;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        string $youtubeId,
        string $title,
        string $uploadsPlaylistId,
        string $encryptedRefreshToken,
        array $scopes,
        \DateTimeImmutable $connectedAt,
    ) {
        $this->youtubeId = $youtubeId;
        $this->title = $title;
        $this->uploadsPlaylistId = $uploadsPlaylistId;
        $this->encryptedRefreshToken = $encryptedRefreshToken;
        $this->scopes = $scopes;
        $this->connectedAt = $connectedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUploadsPlaylistId(): string
    {
        return $this->uploadsPlaylistId;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function getEncryptedRefreshToken(): string
    {
        return $this->encryptedRefreshToken;
    }

    public function getEncryptedAccessToken(): ?string
    {
        return $this->encryptedAccessToken;
    }

    public function getAccessTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function getAnalyticsAvailableUntil(): ?\DateTimeImmutable
    {
        return $this->analyticsAvailableUntil;
    }

    public function getReportingJobId(): ?string
    {
        return $this->reportingJobId;
    }

    public function getLastImportedReportId(): ?string
    {
        return $this->lastImportedReportId;
    }

    public function isReconnectionRequired(): bool
    {
        return $this->reconnectionRequired;
    }

    /**
     * True while the cached access token can still be used, with a safety margin.
     */
    public function hasUsableAccessToken(\DateTimeImmutable $now, int $marginSeconds = 60): bool
    {
        return null !== $this->encryptedAccessToken
            && null !== $this->accessTokenExpiresAt
            && $this->accessTokenExpiresAt->getTimestamp() - $marginSeconds > $now->getTimestamp();
    }

    /**
     * @param list<string> $scopes
     */
    public function reconnect(string $title, string $uploadsPlaylistId, string $encryptedRefreshToken, array $scopes, \DateTimeImmutable $at): void
    {
        $this->title = $title;
        $this->uploadsPlaylistId = $uploadsPlaylistId;
        $this->encryptedRefreshToken = $encryptedRefreshToken;
        $this->scopes = $scopes;
        $this->connectedAt = $at;
        $this->encryptedAccessToken = null;
        $this->accessTokenExpiresAt = null;
        $this->reconnectionRequired = false;
    }

    /**
     * Records who the channel actually is, right after the first API call of a
     * connection. The YouTube identifier is only known at that point.
     */
    public function identify(string $youtubeId, string $title, string $uploadsPlaylistId, ?string $thumbnailUrl): void
    {
        $this->youtubeId = $youtubeId;
        $this->title = $title;
        $this->uploadsPlaylistId = $uploadsPlaylistId;
        $this->thumbnailUrl = $thumbnailUrl;
    }

    public function rename(string $title, ?string $thumbnailUrl): void
    {
        $this->title = $title;
        $this->thumbnailUrl = $thumbnailUrl;
    }

    public function storeAccessToken(string $encryptedAccessToken, \DateTimeImmutable $expiresAt): void
    {
        $this->encryptedAccessToken = $encryptedAccessToken;
        $this->accessTokenExpiresAt = $expiresAt;
        $this->reconnectionRequired = false;
    }

    public function markReconnectionRequired(): void
    {
        $this->reconnectionRequired = true;
        $this->encryptedAccessToken = null;
        $this->accessTokenExpiresAt = null;
    }

    public function forgetAccessToken(): void
    {
        $this->encryptedAccessToken = null;
        $this->accessTokenExpiresAt = null;
    }

    public function markSynced(\DateTimeImmutable $at, ?\DateTimeImmutable $analyticsAvailableUntil): void
    {
        $this->lastSyncedAt = $at;
        if (null !== $analyticsAvailableUntil) {
            $this->analyticsAvailableUntil = $analyticsAvailableUntil->setTime(0, 0);
        }
    }

    public function attachReportingJob(string $jobId): void
    {
        $this->reportingJobId = $jobId;
    }

    public function markReportImported(string $reportId): void
    {
        $this->lastImportedReportId = $reportId;
    }
}
