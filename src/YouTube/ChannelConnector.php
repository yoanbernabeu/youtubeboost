<?php

declare(strict_types=1);

namespace App\YouTube;

use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Api\YouTubeReportingApi;
use App\YouTube\Entity\Channel;
use App\YouTube\Exception\YouTubeException;
use App\YouTube\OAuth\GoogleOAuthClient;
use App\YouTube\OAuth\OAuthTokens;
use App\YouTube\Repository\ChannelRepository;
use App\YouTube\Security\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a fresh OAuth grant into a connected channel.
 *
 * The thumbnail reach reporting job is created here, on purpose: the Reporting
 * API only starts producing data from the moment its job exists, so every day of
 * delay is a day of impressions lost for good.
 */
final class ChannelConnector
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly YouTubeDataApi $dataApi,
        private readonly YouTubeReportingApi $reportingApi,
        private readonly GoogleOAuthClient $oauth,
        private readonly TokenCipher $cipher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function connect(OAuthTokens $tokens): Channel
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $encryptedRefreshToken = $this->cipher->encrypt((string) $tokens->refreshToken);

        $channel = $this->channels->findConnected();
        if (null === $channel) {
            $channel = new Channel('pending', 'Connecting channel', 'pending', $encryptedRefreshToken, $tokens->scopes, $now);
            $this->entityManager->persist($channel);
        } else {
            $channel->reconnect($channel->getTitle(), $channel->getUploadsPlaylistId(), $encryptedRefreshToken, $tokens->scopes, $now);
        }

        // Flushed before the first API call so the access token provider can read it.
        $channel->storeAccessToken($this->cipher->encrypt($tokens->accessToken), $tokens->expiresAt);
        $this->entityManager->flush();

        $info = $this->dataApi->fetchChannel();
        $channel->identify($info->youtubeId, $info->title, $info->uploadsPlaylistId, $info->thumbnailUrl);
        $this->entityManager->flush();

        $this->ensureReachReporting($channel);

        return $channel;
    }

    public function disconnect(): void
    {
        $channel = $this->channels->findConnected();
        if (null === $channel) {
            return;
        }

        try {
            $this->oauth->revoke($this->cipher->decrypt($channel->getEncryptedRefreshToken()));
        } catch (\RuntimeException $exception) {
            $this->logger->info('The refresh token could not be revoked.', ['exception' => $exception]);
        }

        $this->entityManager->remove($channel);
        $this->entityManager->flush();
    }

    /**
     * Creates the reach reporting job, without blocking the connection if the
     * Reporting API is unavailable: the impression signals simply stay neutral.
     */
    public function ensureReachReporting(Channel $channel): void
    {
        if (null !== $channel->getReportingJobId()) {
            return;
        }

        try {
            $channel->attachReportingJob($this->reportingApi->findOrCreateJob());
            $this->entityManager->flush();
        } catch (YouTubeException $exception) {
            $this->logger->warning('The thumbnail reach reporting job could not be created.', ['exception' => $exception]);
        }
    }
}
