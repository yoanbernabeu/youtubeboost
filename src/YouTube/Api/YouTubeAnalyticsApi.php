<?php

declare(strict_types=1);

namespace App\YouTube\Api;

use App\Catalog\Model\DailyPoint;
use App\YouTube\Api\Dto\ContentTypeBreakdown;

/**
 * The YouTube Analytics API, used for the daily view and retention history.
 *
 * This API draws on its own request-based quota rather than the Data API units,
 * so calls are cheap but must be spaced out.
 *
 * Thumbnail impressions and their click-through rate are NOT available here:
 * `impressions` means ad impressions. They come from the Reporting API instead,
 * see {@see YouTubeReportingApi}.
 */
final class YouTubeAnalyticsApi
{
    private const string ENDPOINT = 'https://youtubeanalytics.googleapis.com/v2/reports';

    /** Metrics valid together with `dimensions=day` and `filters=video==`. */
    private const string DAILY_METRICS = 'views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage';

    public function __construct(private readonly GoogleApiRequester $requester)
    {
    }

    /**
     * Daily history of one video. Days without activity are simply absent.
     *
     * @return list<DailyPoint>
     */
    public function fetchDailyStats(string $videoId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $payload = $this->requester->getJson(self::ENDPOINT, [
            'ids' => 'channel==MINE',
            'startDate' => $from->format('Y-m-d'),
            'endDate' => $to->format('Y-m-d'),
            'metrics' => self::DAILY_METRICS,
            'dimensions' => 'day',
            'filters' => 'video==' . $videoId,
            'sort' => 'day',
        ]);

        $table = ResultTable::fromPayload($payload);

        $points = [];
        foreach ($table->rows() as $row) {
            $day = $table->string($row, 'day');
            if (null === $day) {
                continue;
            }

            $points[] = new DailyPoint(
                new \DateTimeImmutable($day . ' 00:00:00'),
                (int) ($table->float($row, 'views') ?? 0.0),
                null,
                null,
                $table->float($row, 'averageViewPercentage'),
                null !== $table->float($row, 'averageViewDuration') ? (int) $table->float($row, 'averageViewDuration') : null,
                (int) ($table->float($row, 'estimatedMinutesWatched') ?? 0.0),
            );
        }

        return $points;
    }

    /**
     * YouTube's own classification of a video, through the views it accumulated
     * under each content type. This is the only reliable way to spot a Short.
     */
    public function fetchContentTypeBreakdown(string $videoId, \DateTimeImmutable $from, \DateTimeImmutable $to): ContentTypeBreakdown
    {
        $payload = $this->requester->getJson(self::ENDPOINT, [
            'ids' => 'channel==MINE',
            'startDate' => $from->format('Y-m-d'),
            'endDate' => $to->format('Y-m-d'),
            'metrics' => 'views',
            'dimensions' => 'creatorContentType',
            'filters' => 'video==' . $videoId,
        ]);

        $table = ResultTable::fromPayload($payload);

        $views = [];
        foreach ($table->rows() as $row) {
            $type = $table->string($row, 'creatorContentType');
            if (null === $type) {
                continue;
            }

            $views[$type] = (int) ($table->float($row, 'views') ?? 0.0);
        }

        return new ContentTypeBreakdown($views);
    }

    /**
     * The most recent day the API actually has data for.
     *
     * Analytics data lags 48 to 72 hours and `endDate` is silently truncated, so
     * the real boundary is read from the answer rather than guessed.
     */
    public function findLatestAvailableDay(\DateTimeImmutable $upTo, int $lookBackDays = 10): ?\DateTimeImmutable
    {
        $payload = $this->requester->getJson(self::ENDPOINT, [
            'ids' => 'channel==MINE',
            'startDate' => $upTo->modify(\sprintf('-%d days', $lookBackDays))->format('Y-m-d'),
            'endDate' => $upTo->format('Y-m-d'),
            'metrics' => 'views',
            'dimensions' => 'day',
            'sort' => 'day',
        ]);

        $table = ResultTable::fromPayload($payload);

        $latest = null;
        foreach ($table->rows() as $row) {
            $day = $table->string($row, 'day');
            if (null !== $day && (null === $latest || $day > $latest)) {
                $latest = $day;
            }
        }

        return null === $latest ? null : new \DateTimeImmutable($latest . ' 00:00:00');
    }
}
