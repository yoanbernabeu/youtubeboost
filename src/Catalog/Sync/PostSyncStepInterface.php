<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Shared\Progress\ProgressReporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work that other modules need to run at the end of a synchronisation, once the
 * statistics and scores are up to date.
 */
#[AutoconfigureTag('app.post_sync_step')]
interface PostSyncStepInterface
{
    public function runAfterSync(\DateTimeImmutable $referenceDate, ProgressReporterInterface $reporter): void;
}
