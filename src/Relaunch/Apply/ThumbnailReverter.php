<?php

declare(strict_types=1);

namespace App\Relaunch\Apply;

use App\Catalog\Entity\RelaunchState;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Exception\RelaunchFailedException;
use App\Relaunch\Storage\ArchiveStore;
use App\Shared\Image\ImageNormalizer;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Exception\YouTubeException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Puts the archived thumbnail back on YouTube.
 */
final class ThumbnailReverter
{
    public function __construct(
        private readonly YouTubeDataApi $api,
        private readonly ArchiveStore $archive,
        private readonly ImageNormalizer $normalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws RelaunchFailedException
     */
    public function revert(Relaunch $relaunch): void
    {
        if (!$relaunch->canBeReverted()) {
            throw RelaunchFailedException::alreadyReverted();
        }

        if (!$this->archive->exists($relaunch->getArchivedPath())) {
            throw RelaunchFailedException::archiveMissing();
        }

        $binary = $this->archive->read($relaunch->getArchivedPath());

        // The archived image comes straight from YouTube, so it is already a valid
        // thumbnail; it only needs the two megabyte check.
        $upload = $this->normalizer->forUpload($binary, $relaunch->getArchivedMimeType());
        $video = $relaunch->getVideo();

        try {
            $thumbnails = $this->api->setThumbnail($video->getYoutubeId(), $upload->binary, $upload->mimeType);
        } catch (YouTubeException $exception) {
            throw RelaunchFailedException::uploadRefused($exception);
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $relaunch->markReverted($now);
        $video->replaceThumbnails($thumbnails);
        $video->recordRelaunchState(RelaunchState::Reverted, $relaunch->getAppliedAt());

        $this->entityManager->flush();
    }
}
