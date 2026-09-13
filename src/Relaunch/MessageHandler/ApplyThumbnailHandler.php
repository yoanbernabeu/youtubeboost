<?php

declare(strict_types=1);

namespace App\Relaunch\MessageHandler;

use App\Job\JobRunner;
use App\Relaunch\Apply\ThumbnailApplier;
use App\Relaunch\Message\ApplyThumbnail;
use App\Shared\Progress\ProgressReporterInterface;
use App\Thumbnail\Repository\ThumbnailProposalRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsMessageHandler]
final readonly class ApplyThumbnailHandler
{
    public function __construct(
        private JobRunner $runner,
        private ThumbnailProposalRepository $proposals,
        private ThumbnailApplier $applier,
    ) {
    }

    public function __invoke(ApplyThumbnail $message): void
    {
        $this->runner->run($message->jobId, function (ProgressReporterInterface $reporter) use ($message): TranslatableInterface {
            $proposal = $this->proposals->find($message->proposalId);
            if (null === $proposal) {
                return new TranslatableMessage('relaunch.job.apply.gone');
            }

            $reporter->progress(0, 2, new TranslatableMessage('relaunch.job.apply.step'));
            $relaunch = $this->applier->apply($proposal);
            $reporter->progress(2, 2);

            return new TranslatableMessage('relaunch.job.apply.done', [
                '%date%' => $relaunch->getAppliedAt()->format('d/m/Y'),
                '%time%' => $relaunch->getAppliedAt()->format('H:i'),
            ]);
        });
    }
}
