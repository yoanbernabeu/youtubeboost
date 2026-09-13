<?php

declare(strict_types=1);

namespace App\Relaunch\Apply;

use App\Catalog\Entity\RelaunchState;
use App\Catalog\Repository\DailyStatRepository;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Exception\RelaunchFailedException;
use App\Relaunch\Model\PerformanceSnapshot;
use App\Relaunch\Storage\ArchiveStore;
use App\Relaunch\Tracking\SnapshotBuilder;
use App\Scoring\ScoringConfiguration;
use App\Shared\Image\ImageNormalizer;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\Storage\ThumbnailStore;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Exception\YouTubeException;
use App\YouTube\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Pushes a generated thumbnail to YouTube, after archiving the one it replaces.
 *
 * The archive comes first on purpose: without it, going back would be impossible,
 * and an irreversible one-click change is not something to offer.
 */
final class ThumbnailApplier
{
    public function __construct(
        private readonly YouTubeDataApi $api,
        private readonly ThumbnailStore $thumbnails,
        private readonly ArchiveStore $archive,
        private readonly CurrentThumbnailFetcher $fetcher,
        private readonly ImageNormalizer $normalizer,
        private readonly DailyStatRepository $dailyStats,
        private readonly SnapshotBuilder $snapshots,
        private readonly ScoringConfiguration $scoring,
        private readonly ChannelRepository $channels,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws RelaunchFailedException
     */
    public function apply(ThumbnailProposal $proposal): Relaunch
    {
        if (!$proposal->isReady()) {
            throw RelaunchFailedException::proposalNotReady();
        }

        $video = $proposal->getVideo();
        $path = (string) $proposal->getImagePath();
        $upload = $this->normalizer->forUpload($this->thumbnails->read($path));

        $current = $this->fetcher->fetch($video->getThumbnails());
        if (null === $current) {
            throw RelaunchFailedException::currentThumbnailUnavailable();
        }

        $archivedPath = $this->archive->write(
            $current->binary,
            $video->getYoutubeId(),
            'avant',
            $current->extension(),
        );

        try {
            $newThumbnails = $this->api->setThumbnail($video->getYoutubeId(), $upload->binary, $upload->mimeType);
        } catch (YouTubeException $exception) {
            $this->archive->delete($archivedPath);

            throw RelaunchFailedException::uploadRefused($exception);
        }

        $now = $this->now();
        $relaunch = new Relaunch(
            $video,
            $proposal,
            $archivedPath,
            $current->mimeType,
            $current->url,
            $path,
            $this->snapshotBefore($video->getYoutubeId(), $now),
            $now,
        );

        $video->replaceThumbnails($newThumbnails);
        $video->recordRelaunchState(RelaunchState::Tracking, $now);
        $proposal->markApplied($now);

        $this->entityManager->persist($relaunch);
        $this->entityManager->flush();

        return $relaunch;
    }

    /**
     * Daily averages over the window that ends just before the change.
     *
     * The window stops at the last day Analytics actually covers, otherwise the
     * two or three days of reporting lag would drag the average down.
     */
    private function snapshotBefore(string $videoId, \DateTimeImmutable $appliedAt): PerformanceSnapshot
    {
        $windowDays = $this->scoring->parameters()->windowDays;
        $available = $this->channels->findConnected()?->getAnalyticsAvailableUntil();
        $end = $appliedAt->setTime(0, 0)->modify('-1 day');
        if (null !== $available && $available < $end) {
            $end = $available->setTime(0, 0);
        }

        return $this->snapshots->build(
            $this->dailyStats->loadSeries($videoId),
            $end->modify(\sprintf('-%d days', $windowDays - 1)),
            $end,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
