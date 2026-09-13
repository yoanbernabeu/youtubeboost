<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\OAuth;

use App\YouTube\Exception\ApiCallFailedException;
use App\YouTube\Exception\AuthorizationRequiredException;
use App\YouTube\OAuth\GoogleOAuthClient;
use App\YouTube\OAuth\OAuthTokens;
use App\YouTube\OAuth\PkcePair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GoogleOAuthClient::class)]
#[CoversClass(OAuthTokens::class)]
#[CoversClass(PkcePair::class)]
final class GoogleOAuthClientTest extends TestCase
{
    public function testAPkcePairDerivesItsChallengeFromItsVerifier(): void
    {
        $pair = PkcePair::generate();

        self::assertSame($pair->challenge, PkcePair::fromVerifier($pair->verifier)->challenge);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9_-]+$#', $pair->verifier);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9_-]+$#', $pair->challenge);
        self::assertNotSame(PkcePair::generate()->verifier, $pair->verifier);
    }

    public function testTheAuthorizationUrlAsksForOfflineAccessAndForcesConsent(): void
    {
        $client = self::client(new MockHttpClient());

        $url = $client->authorizationUrl('a-state', PkcePair::fromVerifier('a-verifier'));

        self::assertStringStartsWith(GoogleOAuthClient::AUTHORIZATION_ENDPOINT . '?', $url);

        $queryString = parse_url($url, \PHP_URL_QUERY);
        parse_str(\is_string($queryString) ? $queryString : '', $query);
        self::assertSame('a-client-id', $query['client_id']);
        self::assertSame('https://boost.test/oauth/callback', $query['redirect_uri']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('offline', $query['access_type']);
        self::assertSame('consent', $query['prompt']);
        self::assertSame('true', $query['include_granted_scopes']);
        self::assertSame('a-state', $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(PkcePair::fromVerifier('a-verifier')->challenge, $query['code_challenge']);
        self::assertSame(implode(' ', GoogleOAuthClient::SCOPES), $query['scope']);
    }

    public function testItIsNotConfiguredWithoutCredentials(): void
    {
        self::assertFalse(self::client(new MockHttpClient(), clientId: '')->isConfigured());
        self::assertTrue(self::client(new MockHttpClient())->isConfigured());
    }

    public function testExchangingACodeReturnsTheTokens(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): ResponseInterface {
            $requests[] = [$method, $url, $options['body'] ?? null];

            return new JsonMockResponse([
                'access_token' => 'ya29.access',
                'expires_in' => 3599,
                'refresh_token' => '1//04refresh',
                'scope' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly',
                'token_type' => 'Bearer',
            ]);
        });

        $tokens = self::client($http)->exchangeAuthorizationCode('4/0Acode', 'a-verifier');

        self::assertSame('ya29.access', $tokens->accessToken);
        self::assertSame('1//04refresh', $tokens->refreshToken);
        self::assertSame('2026-06-30 10:59:59', $tokens->expiresAt->format('Y-m-d H:i:s'));
        self::assertSame([
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        ], $tokens->scopes);

        self::assertSame('POST', $requests[0][0]);
        self::assertSame(GoogleOAuthClient::TOKEN_ENDPOINT, $requests[0][1]);
        self::assertStringContainsString('grant_type=authorization_code', (string) $requests[0][2]);
        self::assertStringContainsString('code_verifier=a-verifier', (string) $requests[0][2]);
    }

    public function testExchangingACodeWithoutRefreshTokenIsAnError(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['access_token' => 'ya29.access', 'expires_in' => 3599]));

        $this->expectException(ApiCallFailedException::class);
        $this->expectExceptionMessageMatches('#refresh token#');

        self::client($http)->exchangeAuthorizationCode('4/0Acode', 'a-verifier');
    }

    public function testAGoogleErrorIsSurfacedWithItsReason(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => 'redirect_uri_mismatch', 'error_description' => 'Bad Request'],
            ['http_code' => 400],
        ));

        try {
            self::client($http)->exchangeAuthorizationCode('4/0Acode', 'a-verifier');
            self::fail('An exception was expected.');
        } catch (ApiCallFailedException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('redirect_uri_mismatch', $exception->reason);
            self::assertStringContainsString('redirect_uri_mismatch', $exception->getMessage());
        }
    }

    public function testRefreshingKeepsWorkingWithoutANewRefreshToken(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'access_token' => 'ya29.fresh',
            'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/youtube.force-ssl',
        ]));

        $tokens = self::client($http)->refreshAccessToken('1//04refresh');

        self::assertSame('ya29.fresh', $tokens->accessToken);
        self::assertNull($tokens->refreshToken);
    }

    public function testAnExpiredRefreshTokenAsksForAReconnection(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'],
            ['http_code' => 400],
        ));

        $this->expectException(AuthorizationRequiredException::class);
        $this->expectExceptionMessageMatches('#authorisation has expired#');

        self::client($http)->refreshAccessToken('1//04refresh');
    }

    public function testAServerErrorOnRefreshIsNotMistakenForAnExpiredToken(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['error' => 'backendError'], ['http_code' => 503]));

        $this->expectException(ApiCallFailedException::class);

        self::client($http)->refreshAccessToken('1//04refresh');
    }

    public function testATokenResponseWithoutAccessTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OAuthTokens::fromTokenResponse(['expires_in' => 10], new \DateTimeImmutable());
    }

    public function testTheDefaultLifetimeIsOneHour(): void
    {
        $tokens = OAuthTokens::fromTokenResponse(
            ['access_token' => 'ya29.access'],
            new \DateTimeImmutable('2026-06-30 10:00:00'),
        );

        self::assertSame('2026-06-30 11:00:00', $tokens->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testRevokingSwallowsTransportErrors(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['error' => 'invalid_token'], ['http_code' => 400]));

        self::client($http)->revoke('1//04refresh');

        $this->expectNotToPerformAssertions();
    }

    private static function client(MockHttpClient $http, string $clientId = 'a-client-id'): GoogleOAuthClient
    {
        return new GoogleOAuthClient(
            $http,
            new MockClock(new \DateTimeImmutable('2026-06-30 10:00:00', new \DateTimeZone('UTC'))),
            $clientId,
            'a-client-secret',
            'https://boost.test/oauth/callback',
        );
    }
}
