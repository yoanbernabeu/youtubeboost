<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Scoring\ScoreRecalculator;
use App\Shared\Progress\ProgressReporterInterface;
use App\YouTube\Api\YouTubeAnalyticsApi;
use App\YouTube\Exception\AuthorizationRequiredException;
use App\YouTube\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs a full catalogue synchronisation, in the order the data depends on.
 *
 * Always triggered by hand: every pass costs either quota or time, and the point
 * of the tool is that nothing expensive happens without an explicit click.
 */
final class CatalogSynchronizer
{
    /** Analytics data lags two to three days; start looking from yesterday. */
    private const int FRESHNESS_LOOKBACK_DAYS = 1;

    /** Used when the Analytics API answers nothing at all. */
    private const int FALLBACK_LATENCY_DAYS = 3;

    /**
     * @param iterable<PostSyncStepInterface> $postSyncSteps
     */
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly VideoImporter $videoImporter,
        private readonly StatsImporter $statsImporter,
        private readonly ReachImporter $reachImporter,
        private readonly ScoreRecalculator $scoreRecalculator,
        private readonly YouTubeAnalyticsApi $analytics,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[AutowireIterator('app.post_sync_step')]
        private readonly iterable $postSyncSteps = [],
    ) {
    }

    /**
     * @throws AuthorizationRequiredException when no channel is connected
     */
    public function synchronize(ProgressReporterInterface $reporter): SyncReport
    {
        $channel = $this->channels->findConnected();
        if (null === $channel) {
            throw AuthorizationRequiredException::notConnected();
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $referenceDate = $this->resolveReferenceDate($now);

        $videos = $this->videoImporter->import($channel, $reporter);
        $stats = $this->statsImporter->import($videos->videoIds, $referenceDate, $reporter);
        $reach = $this->reachImporter->import($channel, $videos->videoIds, $reporter);
        $scored = $this->scoreRecalculator->recalculate($referenceDate, $reporter);

        foreach ($this->postSyncSteps as $step) {
            $step->runAfterSync($referenceDate, $reporter);
        }

        $channel->markSynced($now, $referenceDate);
        $this->entityManager->flush();

        return new SyncReport($referenceDate, $videos, $stats, $reach, $scored);
    }

    /**
     * The last day Analytics actually has data for.
     *
     * `endDate` is silently truncated by the API, so the boundary is read from a
     * real answer rather than assumed from the documented latency.
     */
    private function resolveReferenceDate(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $upTo = $now->setTime(0, 0)->modify(\sprintf('-%d days', self::FRESHNESS_LOOKBACK_DAYS));

        return $this->analytics->findLatestAvailableDay($upTo)
            ?? $now->setTime(0, 0)->modify(\sprintf('-%d days', self::FALLBACK_LATENCY_DAYS));
    }
}
