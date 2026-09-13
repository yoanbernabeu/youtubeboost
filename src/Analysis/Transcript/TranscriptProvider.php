<?php

declare(strict_types=1);

namespace App\Analysis\Transcript;

use App\Analysis\Entity\Transcript;
use App\Analysis\Repository\TranscriptRepository;
use App\Catalog\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Returns the transcript of a video, downloading it at most once.
 *
 * Failures are cached too: re-listing the caption tracks of a video that has none
 * would cost 50 quota units every single time.
 */
final class TranscriptProvider
{
    /** Used when YouTube reports no language for the video. */
    private const string FALLBACK_LANGUAGE = 'fr';

    public function __construct(
        private readonly TranscriptRepository $transcripts,
        private readonly TranscriptDownloader $downloader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function get(Video $video, ?string $preferredLanguage = null, bool $forceRefresh = false): Transcript
    {
        $cached = $this->transcripts->findForVideo($video->getYoutubeId());
        if (null !== $cached && !$forceRefresh) {
            return $cached;
        }

        // The language YouTube reports for the video, then the channel default.
        $language = $preferredLanguage ?? $video->getLanguage() ?? self::FALLBACK_LANGUAGE;

        $draft = $this->downloader->download($video->getYoutubeId(), $language);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if (null !== $cached) {
            $this->entityManager->remove($cached);
            $this->entityManager->flush();
        }

        $transcript = $draft->isUsable()
            ? Transcript::downloaded($video, $draft->source, $draft->language, $draft->text, $now)
            : Transcript::unavailable($video, (string) $draft->reason, $now);

        $this->entityManager->persist($transcript);
        $this->entityManager->flush();

        return $transcript;
    }
}
