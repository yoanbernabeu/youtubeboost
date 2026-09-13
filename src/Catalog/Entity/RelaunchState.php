<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

/**
 * Where a video stands in the relaunch cycle.
 *
 * Denormalised on the video so the catalogue listing can filter and sort without
 * joining the relaunch history.
 */
enum RelaunchState: string
{
    case None = 'none';
    case Tracking = 'tracking';
    case Finished = 'finished';
    case Reverted = 'reverted';
}
