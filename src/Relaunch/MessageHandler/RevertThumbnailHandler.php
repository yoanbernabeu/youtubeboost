<?php

declare(strict_types=1);

namespace App\Relaunch\MessageHandler;

use App\Job\JobRunner;
use App\Relaunch\Apply\ThumbnailReverter;
use App\Relaunch\Message\RevertThumbnail;
use App\Relaunch\Repository\RelaunchRepository;
use App\Shared\Progress\ProgressReporterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsMessageHandler]
final readonly class RevertThumbnailHandler
{
    public function __construct(
        private JobRunner $runner,
        private RelaunchRepository $relaunches,
        private ThumbnailReverter $reverter,
    ) {
    }

    public function __invoke(RevertThumbnail $message): void
    {
        $this->runner->run($message->jobId, function (ProgressReporterInterface $reporter) use ($message): TranslatableInterface {
            $relaunch = $this->relaunches->find($message->relaunchId);
            if (null === $relaunch) {
                return new TranslatableMessage('relaunch.job.revert.gone');
            }

            $reporter->progress(0, 1, new TranslatableMessage('relaunch.job.revert.step'));
            $this->reverter->revert($relaunch);
            $reporter->progress(1, 1);

            return new TranslatableMessage('relaunch.job.revert.done');
        });
    }
}
