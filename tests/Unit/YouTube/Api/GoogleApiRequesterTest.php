<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\Api;

use App\Tests\Support\FakeAccessTokenProvider;
use App\YouTube\Api\GoogleApiRequester;
use App\YouTube\Exception\ApiCallFailedException;
use App\YouTube\Exception\AuthorizationRequiredException;
use App\YouTube\Exception\QuotaExhaustedException;
use App\YouTube\Exception\RateLimitedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GoogleApiRequester::class)]
final class GoogleApiRequesterTest extends TestCase
{
    public function testItSendsTheBearerTokenAndReturnsTheDecodedBody(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

            return new JsonMockResponse(['items' => [['id' => 'abc']]]);
        });

        $payload = new GoogleApiRequester($http, new FakeAccessTokenProvider('ya29.token'))
            ->getJson('https://www.googleapis.com/youtube/v3/videos', ['part' => 'snippet', 'id' => 'abc']);

        self::assertSame([['id' => 'abc']], $payload['items']);
        self::assertSame('GET', $seen['method']);
        self::assertStringContainsString('part=snippet', $seen['url']);
        self::assertContains('Authorization: Bearer ya29.token', $seen['headers']);
    }

    public function testItReturnsRawBodiesForNonJsonEndpoints(): void
    {
        $http = new MockHttpClient(new MockResponse("1\n00:00:01,000 --> 00:00:02,000\nBonjour\n"));

        $content = new GoogleApiRequester($http, new FakeAccessTokenProvider())
            ->getRaw('https://www.googleapis.com/youtube/v3/captions/abc', ['tfmt' => 'srt']);

        self::assertStringContainsString('Bonjour', $content);
    }

    public function testItUploadsABinaryBodyWithItsContentType(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            $seen = ['method' => $method, 'headers' => $options['headers'], 'body' => $options['body']];

            return new JsonMockResponse(['items' => [['default' => ['url' => 'https://i.ytimg.com/vi/x/default.jpg']]]]);
        });

        $payload = new GoogleApiRequester($http, new FakeAccessTokenProvider())
            ->postBinary('https://www.googleapis.com/upload/youtube/v3/thumbnails/set', ['videoId' => 'abc'], 'image/png', 'PNGDATA');

        self::assertArrayHasKey('items', $payload);
        self::assertSame('POST', $seen['method']);
        self::assertContains('Content-Type: image/png', $seen['headers']);
        self::assertSame('PNGDATA', $seen['body']);
    }

    public function testItPostsJsonBodies(): void
    {
        $seen = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
            $seen = $options['body'];

            return new JsonMockResponse(['id' => 'job-1']);
        });

        $payload = new GoogleApiRequester($http, new FakeAccessTokenProvider())
            ->postJson('https://youtubereporting.googleapis.com/v1/jobs', ['reportTypeId' => 'channel_reach_basic_a1']);

        self::assertSame('job-1', $payload['id']);
        self::assertStringContainsString('channel_reach_basic_a1', (string) $seen);
    }

    public function testAnExpiredTokenInvalidatesTheCacheAndAsksForAReconnection(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => ['message' => 'Invalid Credentials', 'errors' => [['reason' => 'authError']]]],
            ['http_code' => 401],
        ));
        $tokens = new FakeAccessTokenProvider();

        try {
            new GoogleApiRequester($http, $tokens)->getJson('https://www.googleapis.com/youtube/v3/videos');
            self::fail('An exception was expected.');
        } catch (AuthorizationRequiredException) {
            self::assertTrue($tokens->invalidated);
        }
    }

    #[DataProvider('quotaReasons')]
    public function testQuotaErrorsAreRecognised(string $reason): void
    {
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => ['message' => 'Quota exceeded', 'errors' => [['reason' => $reason]]]],
            ['http_code' => 403],
        ));

        $this->expectException(QuotaExhaustedException::class);

        new GoogleApiRequester($http, new FakeAccessTokenProvider())->getJson('https://www.googleapis.com/youtube/v3/videos');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotaReasons(): iterable
    {
        yield 'quotaExceeded' => ['quotaExceeded'];
        yield 'dailyLimitExceeded' => ['dailyLimitExceeded'];
    }

    #[DataProvider('retryableResponses')]
    public function testRetryableErrorsAreRecognised(int $status, ?string $reason): void
    {
        $error = ['message' => 'Slow down'];
        if (null !== $reason) {
            $error['errors'] = [['reason' => $reason]];
        }

        $http = new MockHttpClient(new JsonMockResponse(['error' => $error], ['http_code' => $status]));

        $this->expectException(RateLimitedException::class);

        new GoogleApiRequester($http, new FakeAccessTokenProvider())->getJson('https://youtubeanalytics.googleapis.com/v2/reports');
    }

    /**
     * @return iterable<string, array{int, string|null}>
     */
    public static function retryableResponses(): iterable
    {
        yield 'HTTP 429' => [429, null];
        yield 'rateLimitExceeded on 403' => [403, 'rateLimitExceeded'];
        yield 'userRateLimitExceeded on 403' => [403, 'userRateLimitExceeded'];
        yield 'backend error' => [500, 'backendError'];
        yield 'service unavailable' => [503, null];
    }

    public function testAnyOtherErrorKeepsItsStatusAndReason(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => ['message' => 'The caption track could not be found.', 'errors' => [['reason' => 'captionNotFound']]]],
            ['http_code' => 404],
        ));

        try {
            new GoogleApiRequester($http, new FakeAccessTokenProvider())->getRaw('https://www.googleapis.com/youtube/v3/captions/abc');
            self::fail('An exception was expected.');
        } catch (ApiCallFailedException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertSame('captionNotFound', $exception->reason);
            self::assertStringContainsString('could not be found', $exception->getMessage());
        }
    }

    public function testAnErrorWithoutABodyStillCarriesItsStatus(): void
    {
        $http = new MockHttpClient(new MockResponse('not json', ['http_code' => 418]));

        try {
            new GoogleApiRequester($http, new FakeAccessTokenProvider())->getJson('https://www.googleapis.com/youtube/v3/videos');
            self::fail('An exception was expected.');
        } catch (ApiCallFailedException $exception) {
            self::assertSame(418, $exception->statusCode);
            self::assertNull($exception->reason);
        }
    }

    public function testATransportFailureIsWrapped(): void
    {
        $http = new MockHttpClient(static function (): ResponseInterface {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('DNS failure');
        });

        $this->expectException(ApiCallFailedException::class);

        new GoogleApiRequester($http, new FakeAccessTokenProvider())->getJson('https://www.googleapis.com/youtube/v3/videos');
    }
}
