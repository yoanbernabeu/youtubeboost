<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Entity\Analysis;
use App\Analysis\Transcript\TranscriptProvider;
use App\Catalog\Entity\Video;
use App\Shared\Progress\ProgressReporterInterface;
use App\Thumbnail\ProposalLauncher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Analyses one video and queues the five thumbnails that come out of it.
 */
final class AnalysisRunner
{
    public function __construct(
        private readonly TranscriptProvider $transcripts,
        private readonly VideoBriefFactory $briefs,
        private readonly VideoAnalyzerInterface $analyzer,
        private readonly ProposalLauncher $proposals,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(Video $video, ProgressReporterInterface $reporter): Analysis
    {
        $reporter->progress(0, 3, new TranslatableMessage('analysis.job.transcript'));
        $transcript = $this->transcripts->get($video);

        $reporter->progress(1, 3, new TranslatableMessage('analysis.job.reading'));
        $plan = $this->analyzer->analyze($this->briefs->create($video, $transcript));

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $analysis = new Analysis(
            $video,
            $plan->summary,
            $plan->promise,
            $plan->angles,
            $plan->model,
            $transcript->isUsable(),
            $now,
        );
        $video->markAnalyzed($now);

        $this->entityManager->persist($analysis);
        $this->entityManager->flush();

        $reporter->progress(2, 3, new TranslatableMessage('analysis.job.queueing'));
        $this->proposals->launchForAnalysis($analysis);

        $reporter->progress(3, 3);

        return $analysis;
    }
}
