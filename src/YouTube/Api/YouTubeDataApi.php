<?php

declare(strict_types=1);

namespace App\YouTube\Api;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Visibility;
use App\Shared\Time\IsoDuration;
use App\Shared\Type\Scalar;
use App\YouTube\Api\Dto\CaptionTrack;
use App\YouTube\Api\Dto\ChannelInfo;
use App\YouTube\Api\Dto\VideoSnapshot;
use App\YouTube\Exception\ApiCallFailedException;
use App\YouTube\Quota\QuotaRecorderInterface;
use App\YouTube\Quota\YouTubeEndpoint;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The YouTube Data API v3 endpoints this application uses.
 *
 * Every call is journalised against the daily quota, because three of these
 * endpoints are expensive enough to matter.
 */
final class YouTubeDataApi
{
    /** `videos.list` accepts 50 identifiers per call, for a single quota unit. */
    public const int VIDEO_BATCH_SIZE = 50;
    private const string BASE_URL = 'https://www.googleapis.com/youtube/v3/';
    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/';

    public function __construct(
        private readonly GoogleApiRequester $requester,
        private readonly QuotaRecorderInterface $quota,
    ) {
    }

    public function fetchChannel(): ChannelInfo
    {
        $payload = $this->requester->getJson(self::BASE_URL . 'channels', [
            'part' => 'snippet,contentDetails,statistics',
            'mine' => 'true',
        ]);
        $this->quota->record(YouTubeEndpoint::ChannelsList);

        $item = $this->firstItem($payload);
        if (null === $item) {
            throw new ApiCallFailedException('The connected Google account owns no YouTube channel.')->withUserMessage(new TranslatableMessage('youtube.error.no_channel'));
        }

        $snippet = self::subArray($item, 'snippet');
        $relatedPlaylists = self::subArray(self::subArray($item, 'contentDetails'), 'relatedPlaylists');

        $playlistId = $relatedPlaylists['uploads'] ?? null;
        if (!\is_string($playlistId) || '' === $playlistId) {
            throw new ApiCallFailedException('The uploads playlist of the channel cannot be found.')->withUserMessage(new TranslatableMessage('youtube.error.no_uploads_playlist'));
        }

        return new ChannelInfo(
            self::stringValue($item, 'id'),
            self::stringValue($snippet, 'title'),
            $playlistId,
            ThumbnailSet::fromApiPayload(self::subArray($snippet, 'thumbnails'))->best(),
            Scalar::int(self::subArray($item, 'statistics')['videoCount'] ?? null),
        );
    }

    /**
     * Walks the uploads playlist and yields every video identifier.
     *
     * @return iterable<string>
     */
    public function listUploadedVideoIds(string $uploadsPlaylistId): iterable
    {
        $pageToken = null;

        do {
            $query = [
                'part' => 'contentDetails',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 50,
            ];
            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->requester->getJson(self::BASE_URL . 'playlistItems', $query);
            $this->quota->record(YouTubeEndpoint::PlaylistItemsList);

            foreach (self::items($payload) as $item) {
                $videoId = self::subArray($item, 'contentDetails')['videoId'] ?? null;
                if (\is_string($videoId) && '' !== $videoId) {
                    yield $videoId;
                }
            }

            $pageToken = \is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while (null !== $pageToken);
    }

    /**
     * @param list<string> $videoIds at most {@see self::VIDEO_BATCH_SIZE}
     *
     * @return list<VideoSnapshot>
     */
    public function fetchVideos(array $videoIds): array
    {
        if ([] === $videoIds) {
            return [];
        }

        if (\count($videoIds) > self::VIDEO_BATCH_SIZE) {
            throw new \InvalidArgumentException(\sprintf('videos.list accepts at most %d identifiers per call.', self::VIDEO_BATCH_SIZE));
        }

        $payload = $this->requester->getJson(self::BASE_URL . 'videos', [
            'part' => 'snippet,contentDetails,statistics,status,liveStreamingDetails',
            'id' => implode(',', $videoIds),
            'maxResults' => self::VIDEO_BATCH_SIZE,
        ]);
        $this->quota->record(YouTubeEndpoint::VideosList);

        $snapshots = [];
        foreach (self::items($payload) as $item) {
            $snapshots[] = self::toVideoSnapshot($item);
        }

        return $snapshots;
    }

    /**
     * @return list<CaptionTrack>
     */
    public function listCaptionTracks(string $videoId): array
    {
        $payload = $this->requester->getJson(self::BASE_URL . 'captions', [
            'part' => 'snippet',
            'videoId' => $videoId,
        ]);
        $this->quota->record(YouTubeEndpoint::CaptionsList);

        $tracks = [];
        foreach (self::items($payload) as $item) {
            $snippet = self::subArray($item, 'snippet');
            $lastUpdated = $snippet['lastUpdated'] ?? null;

            $tracks[] = new CaptionTrack(
                self::stringValue($item, 'id'),
                self::stringValue($snippet, 'language'),
                strtolower(self::stringValue($snippet, 'trackKind')),
                self::stringValue($snippet, 'name'),
                (bool) ($snippet['isDraft'] ?? false),
                \is_string($lastUpdated) ? new \DateTimeImmutable($lastUpdated) : null,
            );
        }

        return $tracks;
    }

    /**
     * Downloads a caption track. Third-party clients are usually refused the
     * automatic (ASR) tracks, which surfaces as a 403.
     */
    public function downloadCaption(string $captionId, string $format = 'vtt'): string
    {
        $content = $this->requester->getRaw(self::BASE_URL . 'captions/' . urlencode($captionId), ['tfmt' => $format]);
        $this->quota->record(YouTubeEndpoint::CaptionsDownload);

        return $content;
    }

    /**
     * Replaces the thumbnail of a video and returns the new image set.
     */
    public function setThumbnail(string $videoId, string $binary, string $mimeType = 'image/png'): ThumbnailSet
    {
        $payload = $this->requester->postBinary(
            self::UPLOAD_URL . 'thumbnails/set',
            ['videoId' => $videoId, 'uploadType' => 'media'],
            $mimeType,
            $binary,
        );
        $this->quota->record(YouTubeEndpoint::ThumbnailsSet);

        return ThumbnailSet::fromApiPayload($this->firstItem($payload) ?? []);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function toVideoSnapshot(array $item): VideoSnapshot
    {
        $snippet = self::subArray($item, 'snippet');
        $contentDetails = self::subArray($item, 'contentDetails');
        $statistics = self::subArray($item, 'statistics');
        $status = self::subArray($item, 'status');

        $publishedAt = $snippet['publishedAt'] ?? null;
        $customThumbnail = $contentDetails['hasCustomThumbnail'] ?? null;

        return new VideoSnapshot(
            self::stringValue($item, 'id'),
            self::stringValue($snippet, 'title'),
            self::stringValue($snippet, 'description'),
            \is_string($publishedAt) ? new \DateTimeImmutable($publishedAt) : new \DateTimeImmutable('@0'),
            IsoDuration::toSeconds(self::stringValue($contentDetails, 'duration')),
            Visibility::fromPrivacyStatus(self::stringValue($status, 'privacyStatus')),
            ThumbnailSet::fromApiPayload(self::subArray($snippet, 'thumbnails')),
            Scalar::int($statistics['viewCount'] ?? null),
            Scalar::int($statistics['likeCount'] ?? null),
            Scalar::int($statistics['commentCount'] ?? null),
            [] !== self::subArray($item, 'liveStreamingDetails'),
            'true' === ($contentDetails['caption'] ?? 'false'),
            \is_bool($customThumbnail) ? $customThumbnail : null,
            Scalar::nullableString($snippet['defaultAudioLanguage'] ?? $snippet['defaultLanguage'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $payload): array
    {
        $items = $payload['items'] ?? [];
        if (!\is_array($items)) {
            return [];
        }

        $normalised = [];
        foreach ($items as $item) {
            if (\is_array($item)) {
                /** @var array<string, mixed> $item */
                $normalised[] = $item;
            }
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function firstItem(array $payload): ?array
    {
        return self::items($payload)[0] ?? null;
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function subArray(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function stringValue(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
