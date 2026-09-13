<?php

declare(strict_types=1);

namespace App\Job\Entity;

enum JobType: string
{
    case SyncCatalog = 'sync_catalog';
    case AnalyzeVideo = 'analyze_video';
    case GenerateThumbnail = 'generate_thumbnail';
    case IterateThumbnail = 'iterate_thumbnail';
    case ApplyThumbnail = 'apply_thumbnail';
    case RevertThumbnail = 'revert_thumbnail';
}
