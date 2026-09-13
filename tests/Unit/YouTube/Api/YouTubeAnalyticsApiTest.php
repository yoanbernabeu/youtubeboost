<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\Api;

use App\Tests\Support\FakeAccessTokenProvider;
use App\YouTube\Api\Dto\ContentTypeBreakdown;
use App\YouTube\Api\GoogleApiRequester;
use App\YouTube\Api\ResultTable;
use App\YouTube\Api\YouTubeAnalyticsApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(YouTubeAnalyticsApi::class)]
#[CoversClass(ResultTable::class)]
#[CoversClass(ContentTypeBreakdown::class)]
final class YouTubeAnalyticsApiTest extends TestCase
{
    public function testItReadsTheDailyHistoryByColumnName(): void
    {
        $url = null;
        $http = new MockHttpClient(static function (string $method, string $requested) use (&$url): ResponseInterface {
            $url = $requested;

            return new JsonMockResponse([
                'columnHeaders' => [
                    ['name' => 'day', 'columnType' => 'DIMENSION', 'dataType' => 'STRING'],
                    ['name' => 'averageViewPercentage', 'columnType' => 'METRIC', 'dataType' => 'FLOAT'],
                    ['name' => 'views', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
                    ['name' => 'estimatedMinutesWatched', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
                    ['name' => 'averageViewDuration', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
                ],
                'rows' => [
                    ['2026-06-01', 42.7, 1250, 3400, 163],
                    ['2026-06-02', 41.9, 1680, 4510, 161],
                ],
            ]);
        });

        $points = self::api($http)->fetchDailyStats('dQw4w9WgXcQ', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02'));

        self::assertCount(2, $points);
        self::assertSame('2026-06-01', $points[0]->date->format('Y-m-d'));
        self::assertSame(1250, $points[0]->views);
        self::assertSame(42.7, $points[0]->averageViewPercentage);
        self::assertSame(163, $points[0]->averageViewDuration);
        self::assertSame(3400, $points[0]->estimatedMinutesWatched);
        self::assertNull($points[0]->impressions, 'Impressions come from the Reporting API, not this one.');
        self::assertNull($points[0]->clickThroughRate);

        self::assertStringContainsString('ids=channel%3D%3DMINE', (string) $url);
        self::assertStringContainsString('filters=video%3D%3DdQw4w9WgXcQ', (string) $url);
        self::assertStringContainsString('dimensions=day', (string) $url);
        self::assertStringNotContainsString('impressions', (string) $url);
    }

    public function testAVideoWithoutDataReturnsNoPoint(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['columnHeaders' => [['name' => 'day']], 'rows' => []]));

        self::assertSame([], self::api($http)->fetchDailyStats('v1', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02')));
    }

    public function testAResponseWithoutRowsKeyIsTolerated(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['kind' => 'youtubeAnalytics#resultTable']));

        self::assertSame([], self::api($http)->fetchDailyStats('v1', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02')));
    }

    public function testMissingMetricsBecomeNullRatherThanZero(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'columnHeaders' => [['name' => 'day'], ['name' => 'views']],
            'rows' => [['2026-06-01', 10]],
        ]));

        $point = self::api($http)->fetchDailyStats('v1', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-01'))[0];

        self::assertSame(10, $point->views);
        self::assertNull($point->averageViewPercentage);
        self::assertNull($point->averageViewDuration);
        self::assertSame(0, $point->estimatedMinutesWatched);
    }

    public function testItReadsTheContentTypeBreakdown(): void
    {
        $url = null;
        $http = new MockHttpClient(static function (string $method, string $requested) use (&$url): ResponseInterface {
            $url = $requested;

            return new JsonMockResponse([
                'columnHeaders' => [['name' => 'creatorContentType'], ['name' => 'views']],
                'rows' => [['SHORTS', 45000], ['VIDEO_ON_DEMAND', 120]],
            ]);
        });

        $breakdown = self::api($http)->fetchContentTypeBreakdown('v1', new \DateTimeImmutable('2019-01-01'), new \DateTimeImmutable('2026-06-30'));

        self::assertSame(ContentTypeBreakdown::SHORTS, $breakdown->dominantType());
        self::assertSame(['SHORTS' => 45000, 'VIDEO_ON_DEMAND' => 120], $breakdown->toArray());
        self::assertFalse($breakdown->isEmpty());
        self::assertStringContainsString('dimensions=creatorContentType', (string) $url);
    }

    public function testAnUnwatchedVideoHasNoDominantContentType(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['columnHeaders' => [['name' => 'creatorContentType'], ['name' => 'views']], 'rows' => []]));

        $breakdown = self::api($http)->fetchContentTypeBreakdown('v1', new \DateTimeImmutable('2019-01-01'), new \DateTimeImmutable('2026-06-30'));

        self::assertTrue($breakdown->isEmpty());
        self::assertNull($breakdown->dominantType());
    }

    public function testAZeroViewRowDoesNotCountAsData(): void
    {
        $breakdown = new ContentTypeBreakdown(['SHORTS' => 0]);

        self::assertTrue($breakdown->isEmpty());
        self::assertNull($breakdown->dominantType());
    }

    public function testItDiscoversTheLatestDayTheApiHasData(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'columnHeaders' => [['name' => 'day'], ['name' => 'views']],
            'rows' => [['2026-06-25', 10], ['2026-06-27', 10], ['2026-06-26', 10]],
        ]));

        $day = self::api($http)->findLatestAvailableDay(new \DateTimeImmutable('2026-06-30'));

        self::assertSame('2026-06-27', $day?->format('Y-m-d'));
    }

    public function testABrandNewChannelHasNoAvailableDay(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['columnHeaders' => [['name' => 'day']], 'rows' => []]));

        self::assertNull(self::api($http)->findLatestAvailableDay(new \DateTimeImmutable('2026-06-30')));
    }

    private static function api(MockHttpClient $http): YouTubeAnalyticsApi
    {
        return new YouTubeAnalyticsApi(new GoogleApiRequester($http, new FakeAccessTokenProvider()));
    }
}
