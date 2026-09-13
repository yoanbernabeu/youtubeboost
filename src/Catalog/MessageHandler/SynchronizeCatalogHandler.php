<?php

declare(strict_types=1);

namespace App\Catalog\MessageHandler;

use App\Catalog\Message\SynchronizeCatalog;
use App\Catalog\Sync\CatalogSynchronizer;
use App\Job\JobRunner;
use App\Shared\Progress\ProgressReporterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\TranslatableMessage;

#[AsMessageHandler]
final readonly class SynchronizeCatalogHandler
{
    public function __construct(
        private JobRunner $runner,
        private CatalogSynchronizer $synchronizer,
    ) {
    }

    public function __invoke(SynchronizeCatalog $message): void
    {
        $this->runner->run(
            $message->jobId,
            fn (ProgressReporterInterface $reporter): TranslatableMessage => $this->synchronizer->synchronize($reporter)->summary(),
        );
    }
}
