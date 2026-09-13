<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Analysis\AnalysisLauncher;
use App\Analysis\Entity\Analysis;
use App\Analysis\Repository\AnalysisRepository;
use App\Analysis\Repository\TranscriptRepository;
use App\Catalog\Entity\Video;
use App\Catalog\Repository\VideoRepository;
use App\Job\Entity\Job;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Follows the analysis of one video: the running job, then its result.
 *
 * The video identifier is carried rather than the entity, so every poll reads a
 * fresh row instead of a stale one kept by a long-running worker.
 */
#[AsLiveComponent]
final class AnalysisPanel
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $videoId = '';

    public function __construct(
        private readonly VideoRepository $videos,
        private readonly AnalysisRepository $analyses,
        private readonly TranscriptRepository $transcripts,
        private readonly AnalysisLauncher $launcher,
    ) {
    }

    public function getVideo(): ?Video
    {
        return $this->videos->find($this->videoId);
    }

    public function getAnalysis(): ?Analysis
    {
        return $this->analyses->findLatestForVideo($this->videoId);
    }

    public function getJob(): ?Job
    {
        $video = $this->getVideo();

        return null === $video ? null : $this->launcher->runningJobFor($video);
    }

    public function isRunning(): bool
    {
        return null !== $this->getJob();
    }

    /**
     * The reason stored on the transcript is a translation key, so the template
     * runs it through `|trans` like any other message.
     */
    public function getTranscriptWarning(): ?TranslatableMessage
    {
        $transcript = $this->transcripts->findForVideo($this->videoId);
        if (null === $transcript || $transcript->isUsable()) {
            return null;
        }

        return new TranslatableMessage(
            $transcript->getUnavailableReason() ?? 'analysis.transcript.unavailable.none',
        );
    }

    public function getQuotaCost(): int
    {
        return AnalysisLauncher::quotaCost();
    }
}
