<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Exception\AnalysisFailedException;
use App\Analysis\Message\AnalyzeVideo;
use App\Catalog\Entity\Video;
use App\Job\Entity\Job;
use App\Job\Entity\JobType;
use App\Job\JobTracker;
use App\Job\Repository\JobRepository;
use App\Settings\Settings;
use App\YouTube\Quota\QuotaTracker;
use App\YouTube\Quota\YouTubeEndpoint;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts the analysis of a video, after checking the quota can pay for it.
 */
final class AnalysisLauncher
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobTracker $tracker,
        private readonly QuotaTracker $quota,
        private readonly Settings $settings,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Quota an analysis costs at worst: listing the caption tracks and downloading one.
     */
    public static function quotaCost(): int
    {
        return YouTubeEndpoint::CaptionsList->quotaCost() + YouTubeEndpoint::CaptionsDownload->quotaCost();
    }

    public function launch(Video $video): Job
    {
        $running = $this->runningJobFor($video);
        if (null !== $running) {
            return $running;
        }

        if (!$this->settings->isGeminiConfigured()) {
            throw AnalysisFailedException::geminiNotConfigured();
        }

        $this->quota->assertCanAfford(self::quotaCost());

        $job = $this->tracker->create(JobType::AnalyzeVideo, $video->getYoutubeId());
        $this->bus->dispatch(new AnalyzeVideo($video->getYoutubeId(), (int) $job->getId()));

        return $job;
    }

    public function runningJobFor(Video $video): ?Job
    {
        foreach ($this->jobs->findRunning([JobType::AnalyzeVideo]) as $job) {
            if ($job->getSubjectId() === $video->getYoutubeId()) {
                return $job;
            }
        }

        return null;
    }
}
