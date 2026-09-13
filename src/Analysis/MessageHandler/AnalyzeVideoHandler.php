<?php

declare(strict_types=1);

namespace App\Analysis\MessageHandler;

use App\Analysis\AnalysisRunner;
use App\Analysis\Message\AnalyzeVideo;
use App\Catalog\Repository\VideoRepository;
use App\Job\JobRunner;
use App\Shared\Progress\ProgressReporterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

#[AsMessageHandler]
final readonly class AnalyzeVideoHandler
{
    public function __construct(
        private JobRunner $runner,
        private VideoRepository $videos,
        private AnalysisRunner $analysis,
    ) {
    }

    public function __invoke(AnalyzeVideo $message): void
    {
        $this->runner->run($message->jobId, function (ProgressReporterInterface $reporter) use ($message): TranslatableInterface {
            $video = $this->videos->find($message->videoId);
            if (null === $video) {
                return new TranslatableMessage('analysis.job.gone');
            }

            $analysis = $this->analysis->run($video, $reporter);

            return new TranslatableMessage('analysis.job.done', ['%count%' => \count($analysis->getAngles())]);
        });
    }
}
