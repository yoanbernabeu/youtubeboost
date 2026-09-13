<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Analysis\Entity\Analysis;
use App\Analysis\Repository\AnalysisRepository;
use App\Relaunch\RelaunchLauncher;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\Repository\ThumbnailProposalRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The grid of thumbnail proposals, refreshing itself while the worker draws them.
 */
#[AsLiveComponent]
final class ProposalGrid
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $analysisId = null;

    public function __construct(
        private readonly AnalysisRepository $analyses,
        private readonly ThumbnailProposalRepository $proposals,
        private readonly RelaunchLauncher $relaunches,
    ) {
    }

    public function getAnalysis(): ?Analysis
    {
        return null === $this->analysisId ? null : $this->analyses->find($this->analysisId);
    }

    /**
     * @return array<int, ThumbnailProposal> latest proposal of each angle
     */
    public function getProposals(): array
    {
        return null === $this->analysisId ? [] : $this->proposals->findLatestPerAngle($this->analysisId);
    }

    public function isWorking(): bool
    {
        return null !== $this->analysisId && $this->proposals->countUnfinishedForAnalysis($this->analysisId) > 0;
    }

    public function getReadyCount(): int
    {
        return \count(array_filter($this->getProposals(), static fn (ThumbnailProposal $p): bool => $p->isReady()));
    }

    /**
     * True while a thumbnail is being pushed to YouTube, which disables the
     * buttons so the creator cannot fire two writes at once.
     */
    public function isApplying(): bool
    {
        $analysis = $this->getAnalysis();

        return null !== $analysis && [] !== $this->relaunches->runningJobsFor($analysis->getVideo()->getYoutubeId());
    }

    public function getApplyQuotaCost(): int
    {
        return RelaunchLauncher::quotaCost();
    }
}
