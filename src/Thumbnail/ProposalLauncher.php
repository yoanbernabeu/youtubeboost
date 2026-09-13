<?php

declare(strict_types=1);

namespace App\Thumbnail;

use App\Analysis\Entity\Analysis;
use App\Job\Entity\JobType;
use App\Job\JobTracker;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\Generation\ThumbnailPromptBuilder;
use App\Thumbnail\Message\GenerateThumbnail;
use App\Thumbnail\Message\IterateThumbnail;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates the proposal rows and hands the rendering to the worker.
 *
 * Rows are created up front and empty: the grid then shows five placeholders that
 * fill in one by one, instead of nothing at all for two minutes.
 */
final class ProposalLauncher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobTracker $jobs,
        private readonly MessageBusInterface $bus,
        private readonly ThumbnailPromptBuilder $prompts,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ThumbnailProposal> one proposal per angle, still empty
     */
    public function launchForAnalysis(Analysis $analysis): array
    {
        $guidelines = $this->settings->getString(SettingKey::StyleGuidelines);
        $now = $this->now();

        $proposals = [];
        foreach ($analysis->getAngles() as $angle) {
            $proposal = new ThumbnailProposal(
                $analysis,
                $angle->index,
                $angle->overlayText,
                $angle->referenceAngle,
                $this->prompts->forAngle($angle, $guidelines),
                $now,
            );
            $this->entityManager->persist($proposal);
            $proposals[] = $proposal;
        }

        $this->entityManager->flush();

        foreach ($proposals as $proposal) {
            $job = $this->jobs->create(JobType::GenerateThumbnail, $analysis->getVideo()->getYoutubeId());
            $this->bus->dispatch(new GenerateThumbnail((int) $proposal->getId(), (int) $job->getId()));
        }

        return $proposals;
    }

    /**
     * Queues one proposal again with a rewritten prompt.
     */
    public function regenerate(ThumbnailProposal $proposal, ?string $prompt = null): ThumbnailProposal
    {
        if (null !== $prompt && '' !== trim($prompt)) {
            $proposal->replacePrompt(trim($prompt));
        }

        $proposal->markGenerating();
        $this->entityManager->flush();

        $job = $this->jobs->create(JobType::GenerateThumbnail, $proposal->getVideo()->getYoutubeId());
        $this->bus->dispatch(new GenerateThumbnail((int) $proposal->getId(), (int) $job->getId()));

        return $proposal;
    }

    /**
     * Creates a child proposal built on top of an existing image.
     */
    public function iterate(ThumbnailProposal $parent, string $instruction): ThumbnailProposal
    {
        $child = new ThumbnailProposal(
            $parent->getAnalysis(),
            $parent->getAngleIndex(),
            $parent->getOverlayText(),
            $parent->getReferenceAngle(),
            $this->prompts->forIteration(
                $instruction,
                $parent->getOverlayText(),
                $this->settings->getString(SettingKey::StyleGuidelines),
            ),
            $this->now(),
            $parent,
            trim($instruction),
        );

        $this->entityManager->persist($child);
        $this->entityManager->flush();

        $job = $this->jobs->create(JobType::IterateThumbnail, $parent->getVideo()->getYoutubeId());
        $this->bus->dispatch(new IterateThumbnail((int) $child->getId(), (int) $job->getId()));

        return $child;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
