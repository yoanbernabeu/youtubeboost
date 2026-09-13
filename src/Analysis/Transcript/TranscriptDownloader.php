<?php

declare(strict_types=1);

namespace App\Analysis\Transcript;

use App\Analysis\Entity\TranscriptSource;
use App\YouTube\Api\Dto\CaptionTrack;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Exception\QuotaExhaustedException;
use App\YouTube\Exception\YouTubeException;
use Psr\Log\LoggerInterface;

/**
 * Downloads the best caption track a video has, or explains why it could not.
 *
 * `contentDetails.caption` is deliberately ignored: it is false on a video whose
 * only track is automatic, which is the normal case, so it can never be used to
 * decide that a video has nothing to transcribe. Listing costs 50 units, and
 * `TranscriptProvider` caches the outcome — failures included — so it is paid once.
 *
 * An analysis without a transcript still works from the title and description, so
 * a missing track is a degraded mode rather than a failure.
 */
final class TranscriptDownloader
{
    /**
     * Measured on the channel: an automatic track yields about 1 000 characters per
     * minute of speech, so this holds a video of nearly two hours whole — every one
     * in the catalogue but a single exceptional stream. It is roughly 30k tokens,
     * nothing for a model whose context is a million, and it is paid once per video
     * since `TranscriptProvider` caches the result.
     *
     * The previous value of 40 000 was a guess made before any of this was measured,
     * and it silently cut the last half of every video past 43 minutes.
     */
    public const int MAX_CHARACTERS = 120000;

    public function __construct(
        private readonly YouTubeDataApi $api,
        private readonly CaptionTrackSelector $selector,
        private readonly SubtitleParser $parser,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function download(string $videoId, string $preferredLanguage): TranscriptDraft
    {
        try {
            $tracks = $this->api->listCaptionTracks($videoId);
        } catch (QuotaExhaustedException $exception) {
            throw $exception;
        } catch (YouTubeException $exception) {
            $this->logger->warning('Could not list caption tracks.', ['video' => $videoId, 'exception' => $exception]);

            return TranscriptDraft::unavailable('analysis.transcript.unavailable.list_failed');
        }

        $candidates = $this->selector->rank($tracks, $preferredLanguage);
        if ([] === $candidates) {
            return TranscriptDraft::unavailable('analysis.transcript.unavailable.no_track');
        }

        $lastReason = 'analysis.transcript.unavailable.download_failed';

        foreach ($candidates as $track) {
            try {
                $raw = $this->api->downloadCaption($track->id);
            } catch (QuotaExhaustedException $exception) {
                throw $exception;
            } catch (YouTubeException $exception) {
                $lastReason = $track->isAutomatic()
                    ? 'analysis.transcript.unavailable.automatic_refused'
                    : 'analysis.transcript.unavailable.download_refused';
                $this->logger->info('Caption download refused.', [
                    'video' => $videoId,
                    'track' => $track->id,
                    'kind' => $track->trackKind,
                    'exception' => $exception,
                ]);
                continue;
            }

            $text = $this->parser->toPlainText($raw, self::MAX_CHARACTERS);
            if ('' === $text) {
                $lastReason = 'analysis.transcript.unavailable.empty_track';
                continue;
            }

            return TranscriptDraft::downloaded($this->sourceOf($track), $track->language, $text);
        }

        return TranscriptDraft::unavailable($lastReason);
    }

    private function sourceOf(CaptionTrack $track): TranscriptSource
    {
        return $track->isAutomatic() ? TranscriptSource::Automatic : TranscriptSource::Manual;
    }
}
