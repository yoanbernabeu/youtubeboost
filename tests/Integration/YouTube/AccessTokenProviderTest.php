<?php

declare(strict_types=1);

namespace App\Tests\Integration\YouTube;

use App\Tests\Factory\ChannelFactory;
use App\YouTube\Entity\Channel;
use App\YouTube\Exception\AuthorizationRequiredException;
use App\YouTube\OAuth\AccessTokenProvider;
use App\YouTube\OAuth\GoogleOAuthClient;
use App\YouTube\Repository\ChannelRepository;
use App\YouTube\Security\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(AccessTokenProvider::class)]
final class AccessTokenProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string NOW = '2026-06-30 10:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testWithoutAChannelNothingCanBeDone(): void
    {
        $this->expectException(AuthorizationRequiredException::class);
        $this->expectExceptionMessageMatches('#No YouTube channel#');

        $this->provider(new MockHttpClient())->getAccessToken();
    }

    public function testItRefreshesTheAccessTokenAndKeepsIt(): void
    {
        $this->connectedChannel();
        $http = new MockHttpClient(new JsonMockResponse(['access_token' => 'ya29.fresh', 'expires_in' => 3599]));

        $provider = $this->provider($http);

        self::assertSame('ya29.fresh', $provider->getAccessToken());

        $channel = $this->channel();
        $stored = $channel->getEncryptedAccessToken();
        self::assertNotNull($stored);
        self::assertFalse($channel->isReconnectionRequired());
        self::assertSame('ya29.fresh', $this->cipher()->decrypt($stored));
    }

    public function testAStoredTokenIsReusedWithoutCallingGoogle(): void
    {
        $this->connectedChannel();
        $this->provider(new MockHttpClient(new JsonMockResponse(['access_token' => 'ya29.first', 'expires_in' => 3599])))->getAccessToken();

        // A client with no response at all: any HTTP call would fail the test.
        $second = $this->provider(new MockHttpClient([]));

        self::assertSame('ya29.first', $second->getAccessToken());
    }

    public function testAnExpiredStoredTokenIsRefreshed(): void
    {
        $channel = $this->connectedChannel();
        $channel->storeAccessToken($this->cipher()->encrypt('ya29.stale'), new \DateTimeImmutable('2026-06-30 09:00:00'));
        $this->entityManager()->flush();

        $provider = $this->provider(new MockHttpClient(new JsonMockResponse(['access_token' => 'ya29.renewed', 'expires_in' => 3599])));

        self::assertSame('ya29.renewed', $provider->getAccessToken());
    }

    public function testARefusedRefreshTokenAsksForAReconnection(): void
    {
        $this->connectedChannel();
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'],
            ['http_code' => 400],
        ));

        try {
            $this->provider($http)->getAccessToken();
            self::fail('An exception was expected.');
        } catch (AuthorizationRequiredException) {
            $channel = $this->channel();
            self::assertTrue($channel->isReconnectionRequired());
            self::assertNull($channel->getEncryptedAccessToken());
        }
    }

    public function testInvalidatingForgetsTheStoredToken(): void
    {
        $this->connectedChannel();
        $provider = $this->provider(new MockHttpClient(new JsonMockResponse(['access_token' => 'ya29.first', 'expires_in' => 3599])));
        $provider->getAccessToken();

        $provider->invalidate();

        self::assertNull($this->channel()->getEncryptedAccessToken());
    }

    private function connectedChannel(): Channel
    {
        $channel = ChannelFactory::createOne(['encryptedRefreshToken' => $this->cipher()->encrypt('1//04refresh')]);
        $this->entityManager()->flush();

        return $channel;
    }

    private function channel(): Channel
    {
        $this->entityManager()->clear();
        $channel = self::getContainer()->get(ChannelRepository::class)->findConnected();
        self::assertNotNull($channel);

        return $channel;
    }

    private function provider(MockHttpClient $http): AccessTokenProvider
    {
        $container = self::getContainer();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        return new AccessTokenProvider(
            $container->get(ChannelRepository::class),
            $this->entityManager(),
            new GoogleOAuthClient($http, $clock, 'client-id', 'client-secret', 'https://boost.test/oauth/callback'),
            $this->cipher(),
            $clock,
        );
    }

    private function cipher(): TokenCipher
    {
        return new TokenCipher('a-test-application-secret');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
