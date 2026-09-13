<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Transcript;

use App\Analysis\Entity\TranscriptSource;
use App\Analysis\Transcript\CaptionTrackSelector;
use App\Analysis\Transcript\SubtitleParser;
use App\Analysis\Transcript\TranscriptDownloader;
use App\Analysis\Transcript\TranscriptDraft;
use App\Tests\Support\FakeAccessTokenProvider;
use App\Tests\Support\FakeQuotaRecorder;
use App\YouTube\Api\GoogleApiRequester;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Exception\QuotaExhaustedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TranscriptDownloader::class)]
#[CoversClass(TranscriptDraft::class)]
final class TranscriptDownloaderTest extends TestCase
{
    /**
     * The automatic track is the only one most videos have, and the owner is allowed
     * to download it. Probed on the real channel: 762 KB of French VTT for an
     * 80-minute video whose `contentDetails.caption` was false.
     */
    public function testTheAutomaticTrackIsAUsableTranscript(): void
    {
        $quota = new FakeQuotaRecorder();
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [['id' => 'asr', 'snippet' => ['language' => 'fr', 'trackKind' => 'ASR']]]]),
            new MockResponse("WEBVTT\nKind: captions\nLanguage: fr\n\n00:00:01.040 --> 00:00:03.230 align:start position:0%\n \nSalut<00:00:01.319><c> à</c><00:00:01.439><c> tous</c>\n"),
        ], $quota);

        $draft = $downloader->download('v1', 'fr');

        self::assertTrue($draft->isUsable());
        self::assertSame(TranscriptSource::Automatic, $draft->source);
        self::assertSame('Salut à tous', $draft->text);
        self::assertSame(250, $quota->totalCost());
    }

    public function testItDownloadsTheManualFrenchTrack(): void
    {
        $quota = new FakeQuotaRecorder();
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [
                ['id' => 'asr', 'snippet' => ['language' => 'fr', 'trackKind' => 'ASR']],
                ['id' => 'manual', 'snippet' => ['language' => 'fr', 'trackKind' => 'standard']],
            ]]),
            new MockResponse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nBonjour à tous.\n"),
        ], $quota);

        $draft = $downloader->download('v1', 'fr');

        self::assertTrue($draft->isUsable());
        self::assertSame(TranscriptSource::Manual, $draft->source);
        self::assertSame('fr', $draft->language);
        self::assertSame('Bonjour à tous.', $draft->text);
        self::assertSame(250, $quota->totalCost());
    }

    public function testItFallsBackToTheNextTrackWhenADownloadIsRefused(): void
    {
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [
                ['id' => 'manual-en', 'snippet' => ['language' => 'en', 'trackKind' => 'standard']],
                ['id' => 'asr-fr', 'snippet' => ['language' => 'fr', 'trackKind' => 'ASR']],
            ]]),
            new JsonMockResponse(['error' => ['message' => 'Forbidden', 'errors' => [['reason' => 'forbidden']]]], ['http_code' => 403]),
            new MockResponse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nTexte automatique.\n"),
        ]);

        $draft = $downloader->download('v1', 'fr');

        self::assertTrue($draft->isUsable());
        self::assertSame(TranscriptSource::Automatic, $draft->source);
        self::assertSame('Texte automatique.', $draft->text);
    }

    public function testItExplainsThatYoutubeRefusesAutomaticTracks(): void
    {
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [['id' => 'asr', 'snippet' => ['language' => 'fr', 'trackKind' => 'ASR']]]]),
            new JsonMockResponse(['error' => ['message' => 'Forbidden', 'errors' => [['reason' => 'forbidden']]]], ['http_code' => 403]),
        ]);

        $draft = $downloader->download('v1', 'fr');

        self::assertFalse($draft->isUsable());
        self::assertSame('analysis.transcript.unavailable.automatic_refused', $draft->reason);
    }

    public function testAnEmptyTrackIsNotAcceptedAsATranscript(): void
    {
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [['id' => 'manual', 'snippet' => ['language' => 'fr', 'trackKind' => 'standard']]]]),
            new MockResponse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\n"),
        ]);

        $draft = $downloader->download('v1', 'fr');

        self::assertFalse($draft->isUsable());
        self::assertSame('analysis.transcript.unavailable.empty_track', $draft->reason);
    }

    public function testAVideoWithNoUsableTrackIsReported(): void
    {
        $downloader = self::downloader([new JsonMockResponse(['items' => []])]);

        $draft = $downloader->download('v1', 'fr');

        self::assertFalse($draft->isUsable());
        self::assertSame('analysis.transcript.unavailable.no_track', $draft->reason);
    }

    public function testAFailingListingDoesNotBreakTheAnalysis(): void
    {
        $downloader = self::downloader([
            new JsonMockResponse(['error' => ['message' => 'Not found', 'errors' => [['reason' => 'videoNotFound']]]], ['http_code' => 404]),
        ]);

        $draft = $downloader->download('v1', 'fr');

        self::assertFalse($draft->isUsable());
        self::assertSame('analysis.transcript.unavailable.list_failed', $draft->reason);
    }

    public function testAnExhaustedQuotaStopsEverything(): void
    {
        $downloader = self::downloader([
            new JsonMockResponse(['error' => ['message' => 'Quota', 'errors' => [['reason' => 'quotaExceeded']]]], ['http_code' => 403]),
        ]);

        $this->expectException(QuotaExhaustedException::class);

        $downloader->download('v1', 'fr');
    }

    public function testAnExhaustedQuotaDuringDownloadStopsEverything(): void
    {
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [['id' => 'manual', 'snippet' => ['language' => 'fr', 'trackKind' => 'standard']]]]),
            new JsonMockResponse(['error' => ['message' => 'Quota', 'errors' => [['reason' => 'quotaExceeded']]]], ['http_code' => 403]),
        ]);

        $this->expectException(QuotaExhaustedException::class);

        $downloader->download('v1', 'fr');
    }

    public function testALongTranscriptIsTruncated(): void
    {
        $line = str_repeat('mot ', 40000);
        $downloader = self::downloader([
            new JsonMockResponse(['items' => [['id' => 'manual', 'snippet' => ['language' => 'fr', 'trackKind' => 'standard']]]]),
            new MockResponse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n" . $line . "\n"),
        ]);

        $draft = $downloader->download('v1', 'fr');

        self::assertLessThanOrEqual(TranscriptDownloader::MAX_CHARACTERS + 1, mb_strlen($draft->text));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function downloader(array $responses, ?FakeQuotaRecorder $quota = null): TranscriptDownloader
    {
        $api = new YouTubeDataApi(
            new GoogleApiRequester(new MockHttpClient($responses), new FakeAccessTokenProvider()),
            $quota ?? new FakeQuotaRecorder(),
        );

        return new TranscriptDownloader($api, new CaptionTrackSelector(), new SubtitleParser(), new NullLogger());
    }
}
