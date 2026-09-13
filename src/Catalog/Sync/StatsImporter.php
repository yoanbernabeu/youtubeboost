<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Entity\TypeSource;
use App\Catalog\Entity\Video;
use App\Catalog\Repository\DailyStatRepository;
use App\Catalog\Repository\VideoRepository;
use App\Shared\Progress\ProgressReporterInterface;
use App\YouTube\Api\YouTubeAnalyticsApi;
use App\YouTube\Exception\RateLimitedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Second pass of a synchronisation: the daily Analytics history.
 *
 * The Analytics API charges requests rather than quota units, so the cost here is
 * time: one request per video, spaced out to stay within the rate limit.
 */
final class StatsImporter
{
    /**
     * Analytics data is revised for a few days after the fact, so the tail of the
     * already-imported window is always requested again.
     */
    private const int OVERLAP_DAYS = 7;

    /** The `creatorContentType` dimension carries no data before this date. */
    private const string CONTENT_TYPE_EPOCH = '2019-01-01';

    public function __construct(
        private readonly YouTubeAnalyticsApi $api,
        private readonly DailyStatRepository $dailyStats,
        private readonly VideoRepository $videos,
        private readonly VideoTypeClassifier $classifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'int:ANALYTICS_REQUEST_DELAY_MS')]
        private readonly int $requestDelayMs = 250,
    ) {
    }

    /**
     * @param list<string> $videoIds
     */
    public function import(array $videoIds, \DateTimeImmutable $referenceDate, ProgressReporterInterface $reporter): StatsImportResult
    {
        $reporter->step(new TranslatableMessage('sync.step.daily_stats'));

        $total = \count($videoIds);
        $synced = 0;
        $days = 0;
        $confirmed = 0;
        $failures = 0;

        foreach ($videoIds as $index => $videoId) {
            $video = $this->videos->find($videoId);
            if (null === $video) {
                continue;
            }

            try {
                $days += $this->importVideo($video, $referenceDate);
                $confirmed += $this->confirmType($video, $referenceDate) ? 1 : 0;
                ++$synced;
            } catch (RateLimitedException $exception) {
                // Skipping one video is better than losing the whole pass; the
                // next synchronisation picks it up where it stopped.
                ++$failures;
                $this->logger->warning('Analytics rate limit hit, skipping video.', [
                    'video' => $videoId,
                    'exception' => $exception,
                ]);
            }

            $reporter->progress($index + 1, $total, new TranslatableMessage('sync.step.daily_stats'));
            $this->throttle();
        }

        $this->entityManager->flush();

        return new StatsImportResult($synced, $days, $confirmed, $failures);
    }

    private function importVideo(Video $video, \DateTimeImmutable $referenceDate): int
    {
        $from = $this->startDateFor($video);
        if ($from > $referenceDate) {
            return 0;
        }

        $points = $this->api->fetchDailyStats($video->getYoutubeId(), $from, $referenceDate);
        $this->dailyStats->upsertAnalytics($video->getYoutubeId(), $points);
        $video->markStatsSyncedUntil($referenceDate);

        return \count($points);
    }

    /**
     * Asks YouTube what the video actually is, once, for videos the structural
     * heuristic had to guess.
     */
    private function confirmType(Video $video, \DateTimeImmutable $referenceDate): bool
    {
        if (TypeSource::Analytics === $video->getTypeSource() || TypeSource::Manual === $video->getTypeSource()) {
            return false;
        }

        $from = max($video->getPublishedAt(), new \DateTimeImmutable(self::CONTENT_TYPE_EPOCH));
        if ($from > $referenceDate) {
            return false;
        }

        $guess = $this->classifier->fromBreakdown(
            $this->api->fetchContentTypeBreakdown($video->getYoutubeId(), $from, $referenceDate),
        );
        if (null === $guess) {
            return false;
        }

        $video->detectType($guess->type, $guess->source);

        return true;
    }

    private function startDateFor(Video $video): \DateTimeImmutable
    {
        $publishedAt = $video->getPublishedAt()->setTime(0, 0);
        $syncedUntil = $video->getStatsSyncedUntil();

        if (null === $syncedUntil) {
            return $publishedAt;
        }

        return max($publishedAt, $syncedUntil->modify(\sprintf('-%d days', self::OVERLAP_DAYS)));
    }

    private function throttle(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }
}
