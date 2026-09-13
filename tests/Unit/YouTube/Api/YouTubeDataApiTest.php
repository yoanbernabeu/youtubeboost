<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\Api;

use App\Catalog\Entity\Visibility;
use App\Tests\Support\FakeAccessTokenProvider;
use App\Tests\Support\FakeQuotaRecorder;
use App\YouTube\Api\Dto\CaptionTrack;
use App\YouTube\Api\Dto\ChannelInfo;
use App\YouTube\Api\Dto\VideoSnapshot;
use App\YouTube\Api\GoogleApiRequester;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Exception\ApiCallFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(YouTubeDataApi::class)]
#[CoversClass(ChannelInfo::class)]
#[CoversClass(VideoSnapshot::class)]
#[CoversClass(CaptionTrack::class)]
final class YouTubeDataApiTest extends TestCase
{
    public function testItReadsTheChannelAndItsUploadsPlaylist(): void
    {
        $quota = new FakeQuotaRecorder();
        $api = self::api([new JsonMockResponse(['items' => [[
            'id' => 'UC123',
            'snippet' => [
                'title' => 'Ma chaîne',
                'thumbnails' => ['high' => ['url' => 'https://yt3.test/high.jpg', 'width' => 800, 'height' => 800]],
            ],
            'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU123']],
            'statistics' => ['videoCount' => '412'],
        ]]])], $quota);

        $channel = $api->fetchChannel();

        self::assertSame('UC123', $channel->youtubeId);
        self::assertSame('Ma chaîne', $channel->title);
        self::assertSame('UU123', $channel->uploadsPlaylistId);
        self::assertSame('https://yt3.test/high.jpg', $channel->thumbnailUrl);
        self::assertSame(412, $channel->videoCount);
        self::assertSame(1, $quota->totalCost());
    }

    public function testAGoogleAccountWithoutChannelIsReported(): void
    {
        $api = self::api([new JsonMockResponse(['items' => []])]);

        $this->expectException(ApiCallFailedException::class);
        $this->expectExceptionMessageMatches('#owns no YouTube channel#');

        $api->fetchChannel();
    }

    public function testAChannelWithoutUploadsPlaylistIsReported(): void
    {
        $api = self::api([new JsonMockResponse(['items' => [['id' => 'UC123', 'snippet' => ['title' => 'X']]]])]);

        $this->expectException(ApiCallFailedException::class);
        $this->expectExceptionMessageMatches('#playlist#');

        $api->fetchChannel();
    }

    public function testItPaginatesThroughTheUploadsPlaylist(): void
    {
        $quota = new FakeQuotaRecorder();
        $api = self::api([
            new JsonMockResponse([
                'items' => [
                    ['contentDetails' => ['videoId' => 'v1']],
                    ['contentDetails' => ['videoId' => 'v2']],
                ],
                'nextPageToken' => 'page2',
            ]),
            new JsonMockResponse(['items' => [['contentDetails' => ['videoId' => 'v3']]]]),
        ], $quota);

        self::assertSame(['v1', 'v2', 'v3'], iterator_to_array($api->listUploadedVideoIds('UU123'), false));
        self::assertSame(2, $quota->totalCost());
    }

    public function testItSkipsPlaylistEntriesWithoutAVideoId(): void
    {
        $api = self::api([new JsonMockResponse(['items' => [
            ['contentDetails' => ['videoId' => 'v1']],
            ['contentDetails' => []],
            ['snippet' => []],
        ]])]);

        self::assertSame(['v1'], iterator_to_array($api->listUploadedVideoIds('UU123'), false));
    }

    public function testItNormalisesAVideo(): void
    {
        $quota = new FakeQuotaRecorder();
        $api = self::api([new JsonMockResponse(['items' => [[
            'id' => 'dQw4w9WgXcQ',
            'snippet' => [
                'publishedAt' => '2024-08-01T09:00:04Z',
                'title' => 'Titre',
                'description' => 'Description',
                'defaultAudioLanguage' => 'fr',
                'thumbnails' => [
                    'default' => ['url' => 'https://i.ytimg.com/vi/x/default.jpg', 'width' => 120, 'height' => 90],
                    'maxres' => ['url' => 'https://i.ytimg.com/vi/x/maxresdefault.jpg', 'width' => 1280, 'height' => 720],
                ],
            ],
            'contentDetails' => ['duration' => 'PT15M33S', 'caption' => 'true', 'hasCustomThumbnail' => true],
            'statistics' => ['viewCount' => '1234567', 'likeCount' => '45678', 'commentCount' => '2345'],
            'status' => ['privacyStatus' => 'unlisted'],
        ]]])], $quota);

        $videos = $api->fetchVideos(['dQw4w9WgXcQ']);

        self::assertCount(1, $videos);
        $video = $videos[0];
        self::assertSame('dQw4w9WgXcQ', $video->youtubeId);
        self::assertSame('2024-08-01', $video->publishedAt->format('Y-m-d'));
        self::assertSame(933, $video->durationSeconds);
        self::assertSame(Visibility::Unlisted, $video->visibility);
        self::assertSame('https://i.ytimg.com/vi/x/maxresdefault.jpg', $video->thumbnails->best());
        self::assertSame(1234567, $video->viewCount);
        self::assertSame(45678, $video->likeCount);
        self::assertSame(2345, $video->commentCount);
        self::assertFalse($video->isLiveBroadcast);
        self::assertTrue($video->captionsAvailable);
        self::assertTrue($video->customThumbnail);
        self::assertSame('fr', $video->language);
        self::assertSame(1, $quota->totalCost());
    }

    public function testALiveStreamIsFlaggedByItsStreamingDetails(): void
    {
        $api = self::api([new JsonMockResponse(['items' => [[
            'id' => 'live1',
            'snippet' => ['publishedAt' => '2024-08-01T09:00:04Z', 'title' => 'Live'],
            'contentDetails' => ['duration' => 'PT2H'],
            'status' => ['privacyStatus' => 'public'],
            'liveStreamingDetails' => ['actualStartTime' => '2024-08-01T19:00:00Z'],
        ]]])]);

        self::assertTrue($api->fetchVideos(['live1'])[0]->isLiveBroadcast);
    }

    public function testAVideoWithoutOwnerFieldsStillLoads(): void
    {
        $api = self::api([new JsonMockResponse(['items' => [[
            'id' => 'v1',
            'snippet' => ['publishedAt' => '2024-08-01T09:00:04Z', 'title' => 'Titre'],
            'contentDetails' => ['duration' => 'PT1M'],
            'status' => ['privacyStatus' => 'public'],
        ]]])]);

        $video = $api->fetchVideos(['v1'])[0];

        self::assertNull($video->customThumbnail);
        self::assertNull($video->language);
        self::assertFalse($video->captionsAvailable);
        self::assertSame('', $video->description);
        self::assertTrue($video->thumbnails->isEmpty());
    }

    public function testFetchingNoVideoCostsNothing(): void
    {
        $quota = new FakeQuotaRecorder();
        $api = self::api([], $quota);

        self::assertSame([], $api->fetchVideos([]));
        self::assertSame(0, $quota->totalCost());
    }

    public function testFetchingMoreThanABatchIsRefused(): void
    {
        $api = self::api([]);

        $this->expectException(\InvalidArgumentException::class);

        $api->fetchVideos(array_fill(0, 51, 'v'));
    }

    public function testItListsCaptionTracksAndNormalisesTheirKind(): void
    {
        $quota = new FakeQuotaRecorder();
        $api = self::api([new JsonMockResponse(['items' => [
            [
                'id' => 'track-manual',
                'snippet' => ['language' => 'fr-FR', 'trackKind' => 'standard', 'name' => 'Français', 'isDraft' => false, 'lastUpdated' => '2026-08-02T11:04:22Z'],
            ],
            [
                'id' => 'track-asr',
                'snippet' => ['language' => 'fr', 'trackKind' => 'ASR', 'name' => '', 'isDraft' => false],
            ],
        ]])], $quota);

        $tracks = $api->listCaptionTracks('dQw4w9WgXcQ');

        self::assertCount(2, $tracks);
        self::assertSame('standard', $tracks[0]->trackKind);
        self::assertFalse($tracks[0]->isAutomatic());
        self::assertSame('2026-08-02', $tracks[0]->lastUpdated?->format('Y-m-d'));
        self::assertTrue($tracks[1]->isAutomatic());
        self::assertNull($tracks[1]->lastUpdated);
        self::assertTrue($tracks[0]->matchesLanguage('fr'));
        self::assertTrue($tracks[1]->matchesLanguage('fr-CA'));
        self::assertFalse($tracks[0]->matchesLanguage('en'));
        self::assertSame(50, $quota->totalCost());
    }

    public function testItDownloadsACaptionTrack(): void
    {
        $quota = new FakeQuotaRecorder();
        $api = self::api([new MockResponse("WEBVTT\n\n00:00.000 --> 00:02.000\nBonjour\n")], $quota);

        $content = $api->downloadCaption('track-manual');

        self::assertStringContainsString('Bonjour', $content);
        self::assertSame(200, $quota->totalCost());
    }

    public function testItUploadsAThumbnailAndReturnsTheNewSet(): void
    {
        $quota = new FakeQuotaRecorder();
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

            return new JsonMockResponse(['items' => [[
                'maxres' => ['url' => 'https://i.ytimg.com/vi/x/maxresdefault.jpg', 'width' => 1280, 'height' => 720],
            ]]]);
        });
        $api = new YouTubeDataApi(new GoogleApiRequester($http, new FakeAccessTokenProvider()), $quota);

        $set = $api->setThumbnail('dQw4w9WgXcQ', 'PNGDATA');

        self::assertSame('https://i.ytimg.com/vi/x/maxresdefault.jpg', $set->best());
        self::assertSame('POST', $seen['method']);
        self::assertStringContainsString('/upload/youtube/v3/thumbnails/set', $seen['url']);
        self::assertStringContainsString('uploadType=media', $seen['url']);
        self::assertContains('Content-Type: image/png', $seen['headers']);
        self::assertSame(50, $quota->totalCost());
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function api(array $responses, ?FakeQuotaRecorder $quota = null): YouTubeDataApi
    {
        return new YouTubeDataApi(
            new GoogleApiRequester(new MockHttpClient($responses), new FakeAccessTokenProvider()),
            $quota ?? new FakeQuotaRecorder(),
        );
    }
}
