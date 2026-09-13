<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Entity\Video;
use App\Catalog\Repository\VideoRepository;
use App\Shared\Progress\ProgressReporterInterface;
use App\YouTube\Api\Dto\VideoSnapshot;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * First pass of a synchronisation: the catalogue metadata.
 *
 * Cheap on purpose. Walking the uploads playlist and reading videos in batches of
 * fifty costs about twenty quota units for three hundred videos, where a single
 * `search.list` call would cost a hundred.
 */
final class VideoImporter
{
    public function __construct(
        private readonly YouTubeDataApi $api,
        private readonly VideoRepository $videos,
        private readonly VideoTypeClassifier $classifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function import(Channel $channel, ProgressReporterInterface $reporter): VideoImportResult
    {
        $reporter->step(new TranslatableMessage('sync.step.catalogue'));

        $known = $this->videos->findAllIndexedById();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $seen = [];
        $created = 0;
        $updated = 0;
        $batch = [];

        foreach ($this->api->listUploadedVideoIds($channel->getUploadsPlaylistId()) as $videoId) {
            $batch[] = $videoId;

            if (\count($batch) < YouTubeDataApi::VIDEO_BATCH_SIZE) {
                continue;
            }

            [$batchCreated, $batchUpdated] = $this->importBatch($batch, $known, $now);
            $created += $batchCreated;
            $updated += $batchUpdated;
            $seen = [...$seen, ...$batch];
            $batch = [];

            $reporter->progress(\count($seen), \count($seen) + YouTubeDataApi::VIDEO_BATCH_SIZE, new TranslatableMessage('sync.step.video_metadata'));
        }

        if ([] !== $batch) {
            [$batchCreated, $batchUpdated] = $this->importBatch($batch, $known, $now);
            $created += $batchCreated;
            $updated += $batchUpdated;
            $seen = [...$seen, ...$batch];
        }

        $this->entityManager->flush();

        $removed = $this->videos->deleteMissing($seen);

        $reporter->progress(\count($seen), \count($seen), new TranslatableMessage('sync.step.video_metadata'));

        return new VideoImportResult($seen, $created, $updated, $removed);
    }

    /**
     * @param list<string>         $videoIds
     * @param array<string, Video> $known
     *
     * @return array{0: int, 1: int} created and updated counts
     */
    private function importBatch(array $videoIds, array &$known, \DateTimeImmutable $now): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->api->fetchVideos($videoIds) as $snapshot) {
            $video = $known[$snapshot->youtubeId] ?? null;

            if (null === $video) {
                $video = $this->create($snapshot, $now);
                $known[$snapshot->youtubeId] = $video;
                $this->entityManager->persist($video);
                ++$created;
            } else {
                $video->updateMetadata(
                    $snapshot->title,
                    $snapshot->description,
                    $snapshot->publishedAt,
                    $snapshot->durationSeconds,
                    $snapshot->visibility,
                    $snapshot->thumbnails,
                    $now,
                );
                ++$updated;
            }

            $video->updateStatistics($snapshot->viewCount, $snapshot->likeCount, $snapshot->commentCount);
            $video->updateContentFlags($snapshot->captionsAvailable, $snapshot->customThumbnail, $snapshot->language);

            $guess = $this->classifier->fromSnapshot($snapshot);
            $video->detectType($guess->type, $guess->source);
        }

        return [$created, $updated];
    }

    private function create(VideoSnapshot $snapshot, \DateTimeImmutable $now): Video
    {
        return new Video(
            $snapshot->youtubeId,
            $snapshot->title,
            $snapshot->description,
            $snapshot->publishedAt,
            $snapshot->durationSeconds,
            $snapshot->visibility,
            $snapshot->thumbnails,
            $now,
        );
    }
}
