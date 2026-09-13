<?php

declare(strict_types=1);

namespace App\Scoring\Model;

enum IneligibilityReason: string
{
    case NotAStandardVideo = 'not_a_standard_video';
    case NotVisible = 'not_visible';
    case TooRecent = 'too_recent';
    case RelaunchInProgress = 'relaunch_in_progress';
}
