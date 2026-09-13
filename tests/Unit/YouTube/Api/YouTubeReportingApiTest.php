<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\Api;

use App\Tests\Support\FakeAccessTokenProvider;
use App\YouTube\Api\Dto\ReachRow;
use App\YouTube\Api\Dto\ReportRef;
use App\YouTube\Api\GoogleApiRequester;
use App\YouTube\Api\ReachReportParser;
use App\YouTube\Api\YouTubeReportingApi;
use App\YouTube\Exception\ApiCallFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(YouTubeReportingApi::class)]
#[CoversClass(ReachReportParser::class)]
#[CoversClass(ReportRef::class)]
#[CoversClass(ReachRow::class)]
final class YouTubeReportingApiTest extends TestCase
{
    public function testItReusesAnExistingJob(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$calls): ResponseInterface {
            ++$calls;

            return new JsonMockResponse(['jobs' => [
                ['id' => 'other', 'reportTypeId' => 'channel_basic_a2'],
                ['id' => 'reach-job', 'reportTypeId' => YouTubeReportingApi::REACH_REPORT_TYPE],
            ]]);
        });

        self::assertSame('reach-job', self::api($http)->findOrCreateJob());
        self::assertSame(1, $calls, 'No job should be created when one already exists.');
    }

    public function testItCreatesTheJobWhenThereIsNone(): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse(['jobs' => []]),
            new JsonMockResponse(['id' => 'new-job', 'reportTypeId' => YouTubeReportingApi::REACH_REPORT_TYPE]),
        ]);

        self::assertSame('new-job', self::api($http)->findOrCreateJob());
    }

    public function testAJobCreationWithoutIdentifierIsAnError(): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse(['jobs' => []]),
            new JsonMockResponse(['name' => 'created but no id']),
        ]);

        $this->expectException(ApiCallFailedException::class);

        self::api($http)->findOrCreateJob();
    }

    public function testAChannelWithoutJobAnswersNull(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['jobs' => []]));

        self::assertNull(self::api($http)->findJob());
    }

    public function testItListsReportsOldestFirstAcrossPages(): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse([
                'reports' => [
                    ['id' => 'r2', 'jobId' => 'job', 'startTime' => '2026-06-02T00:00:00Z', 'endTime' => '2026-06-03T00:00:00Z', 'createTime' => '2026-06-04T00:00:00Z', 'downloadUrl' => 'https://dl.test/r2'],
                    ['id' => 'r1', 'jobId' => 'job', 'startTime' => '2026-06-01T00:00:00Z', 'endTime' => '2026-06-02T00:00:00Z', 'createTime' => '2026-06-03T00:00:00Z', 'downloadUrl' => 'https://dl.test/r1'],
                ],
                'nextPageToken' => 'p2',
            ]),
            new JsonMockResponse(['reports' => [
                ['id' => 'r3', 'jobId' => 'job', 'startTime' => '2026-06-03T00:00:00Z', 'endTime' => '2026-06-04T00:00:00Z', 'createTime' => '2026-06-05T00:00:00Z', 'downloadUrl' => 'https://dl.test/r3'],
            ]]),
        ]);

        $reports = self::api($http)->listReports('job');

        self::assertSame(['r1', 'r2', 'r3'], array_map(static fn (ReportRef $r): string => $r->id, $reports));
        self::assertSame('2026-06-01', $reports[0]->startTime->format('Y-m-d'));
    }

    public function testReportsWithoutDownloadUrlAreIgnored(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['reports' => [
            ['id' => 'r1', 'jobId' => 'job'],
            ['id' => 'r2', 'jobId' => 'job', 'downloadUrl' => 'https://dl.test/r2'],
        ]]));

        $reports = self::api($http)->listReports('job');

        self::assertCount(1, $reports);
        self::assertSame('r2', $reports[0]->id);
    }

    public function testItDownloadsAPlainCsv(): void
    {
        $http = new MockHttpClient(new MockResponse("date,video_id\n20260601,v1\n"));

        $content = self::api($http)->downloadReport(self::report());

        self::assertStringContainsString('20260601,v1', $content);
    }

    public function testItDecompressesAGzippedReport(): void
    {
        $http = new MockHttpClient(new MockResponse((string) gzencode("date,video_id\n20260601,v1\n")));

        self::assertStringContainsString('20260601,v1', self::api($http)->downloadReport(self::report()));
    }

    public function testACorruptedGzipStreamIsReported(): void
    {
        $http = new MockHttpClient(new MockResponse("\x1f\x8bnot really gzip"));

        $this->expectException(ApiCallFailedException::class);

        self::api($http)->downloadReport(self::report());
    }

    public function testTheParserReadsImpressionsAndConvertsRatiosToPercentages(): void
    {
        $csv = <<<'CSV'
            date,channel_id,video_id,video_thumbnail_impressions,video_thumbnail_impressions_ctr
            20260601,UC123,v1,12000,0.042
            20260601,UC123,v2,500,0.1
            20260602,UC123,v1,9000,0.038
            CSV;

        $rows = new ReachReportParser()->parse($csv);

        self::assertCount(3, $rows);
        self::assertSame('v1', $rows[0]->videoId);
        self::assertSame('2026-06-01', $rows[0]->date->format('Y-m-d'));
        self::assertSame(12000, $rows[0]->impressions);
        self::assertEqualsWithDelta(4.2, $rows[0]->clickThroughRate, 0.0001);
        self::assertEqualsWithDelta(10.0, $rows[1]->clickThroughRate, 0.0001);
    }

    public function testTheParserLeavesPercentagesAlone(): void
    {
        $csv = "date,channel_id,video_id,video_thumbnail_impressions,video_thumbnail_impressions_ctr\n20260601,UC123,v1,12000,4.2\n";

        $rows = new ReachReportParser()->parse($csv);

        self::assertEqualsWithDelta(4.2, $rows[0]->clickThroughRate, 0.0001);
    }

    public function testTheParserIsIndifferentToColumnOrder(): void
    {
        $csv = "video_thumbnail_impressions_ctr,video_id,date,channel_id,video_thumbnail_impressions\n3.5,v9,2026-06-01,UC1,700\n";

        $rows = new ReachReportParser()->parse($csv);

        self::assertSame('v9', $rows[0]->videoId);
        self::assertSame(700, $rows[0]->impressions);
        self::assertSame('2026-06-01', $rows[0]->date->format('Y-m-d'));
    }

    public function testTheParserIgnoresMalformedAndEmptyRows(): void
    {
        $csv = "date,channel_id,video_id,video_thumbnail_impressions,video_thumbnail_impressions_ctr\n"
            . "notadate,UC1,v1,10,2\n"
            . ",UC1,v2,10,2\n"
            . "20260601,UC1,,10,2\n"
            . "\n"
            . "20260601,UC1,v3,10,2\n";

        $rows = new ReachReportParser()->parse($csv);

        self::assertCount(1, $rows);
        self::assertSame('v3', $rows[0]->videoId);
    }

    public function testTheParserRejectsAReportWithoutTheExpectedColumns(): void
    {
        self::assertSame([], new ReachReportParser()->parse("date,video_id\n20260601,v1\n"));
        self::assertSame([], new ReachReportParser()->parse(''));
        self::assertSame([], new ReachReportParser()->parse("date,channel_id,video_id,video_thumbnail_impressions,video_thumbnail_impressions_ctr\n"));
    }

    private static function report(): ReportRef
    {
        return new ReportRef(
            'r1',
            'job',
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-02'),
            new \DateTimeImmutable('2026-06-03'),
            'https://dl.test/r1',
        );
    }

    private static function api(MockHttpClient $http): YouTubeReportingApi
    {
        return new YouTubeReportingApi(new GoogleApiRequester($http, new FakeAccessTokenProvider()));
    }
}
