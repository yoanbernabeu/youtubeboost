<?php

declare(strict_types=1);

namespace App\YouTube\OAuth;

use App\YouTube\Entity\Channel;
use App\YouTube\Exception\AuthorizationRequiredException;
use App\YouTube\Repository\ChannelRepository;
use App\YouTube\Security\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands out a usable access token, refreshing it from the stored refresh token.
 *
 * The fresh token is cached in the database so a restart, or a second process,
 * does not burn another refresh call.
 */
#[AsAlias(AccessTokenProviderInterface::class)]
final class AccessTokenProvider implements AccessTokenProviderInterface, ResetInterface
{
    private ?string $cached = null;

    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleOAuthClient $oauth,
        private readonly TokenCipher $cipher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getAccessToken(): string
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        $channel = $this->channels->findConnected();
        if (null === $channel) {
            throw AuthorizationRequiredException::notConnected();
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($channel->hasUsableAccessToken($now)) {
            return $this->cached = $this->cipher->decrypt((string) $channel->getEncryptedAccessToken());
        }

        return $this->cached = $this->refresh($channel, $now);
    }

    public function invalidate(): void
    {
        $this->cached = null;

        $channel = $this->channels->findConnected();
        if (null === $channel) {
            return;
        }

        $channel->forgetAccessToken();
        $this->entityManager->flush();
    }

    public function reset(): void
    {
        $this->cached = null;
    }

    private function refresh(Channel $channel, \DateTimeImmutable $now): string
    {
        try {
            $tokens = $this->oauth->refreshAccessToken($this->cipher->decrypt($channel->getEncryptedRefreshToken()));
        } catch (AuthorizationRequiredException $exception) {
            // Surfaced as a banner in the interface rather than only in a job log.
            $channel->markReconnectionRequired();
            $this->entityManager->flush();

            throw $exception;
        }

        $channel->storeAccessToken($this->cipher->encrypt($tokens->accessToken), $tokens->expiresAt);
        $this->entityManager->flush();

        return $tokens->accessToken;
    }
}
