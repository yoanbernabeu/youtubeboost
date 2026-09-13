<?php

declare(strict_types=1);

namespace App\Thumbnail\MessageHandler;

use App\Job\JobRunner;
use App\Shared\Progress\ProgressReporterInterface;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\Message\GenerateThumbnail;
use App\Thumbnail\Message\IterateThumbnail;
use App\Thumbnail\Repository\ThumbnailProposalRepository;
use App\Thumbnail\ThumbnailRenderer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Renders one proposal, whether it is a first pass or an iteration.
 *
 * The five proposals of an analysis are five separate messages, so the worker can
 * run them in parallel and the grid fills in as they land.
 */
#[AsMessageHandler(handles: GenerateThumbnail::class)]
#[AsMessageHandler(handles: IterateThumbnail::class)]
final readonly class RenderProposalHandler
{
    public function __construct(
        private JobRunner $runner,
        private ThumbnailProposalRepository $proposals,
        private ThumbnailRenderer $renderer,
    ) {
    }

    public function __invoke(GenerateThumbnail|IterateThumbnail $message): void
    {
        $this->runner->run($message->jobId, function (ProgressReporterInterface $reporter) use ($message): TranslatableInterface {
            $proposal = $this->proposals->find($message->proposalId);
            if (null === $proposal) {
                return new TranslatableMessage('thumbnail.job.gone');
            }

            $reporter->progress(0, 1, new TranslatableMessage('thumbnail.job.step', ['%number%' => $proposal->getAngleIndex() + 1]));
            $this->renderer->render($proposal);
            $reporter->progress(1, 1);

            return $this->summaryOf($proposal);
        });
    }

    private function summaryOf(ThumbnailProposal $proposal): TranslatableInterface
    {
        if ($proposal->isReady()) {
            return new TranslatableMessage('thumbnail.job.done', ['%number%' => $proposal->getAngleIndex() + 1]);
        }

        return new TranslatableMessage('thumbnail.job.failed', [
            '%number%' => $proposal->getAngleIndex() + 1,
            '%error%' => (string) $proposal->getErrorMessage(),
        ]);
    }
}
