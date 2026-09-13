<?php

declare(strict_types=1);

namespace App\Relaunch\Entity;

use Symfony\Component\Translation\TranslatableMessage;

enum RelaunchStatus: string
{
    /** The new thumbnail is live and the 14 and 28 day milestones are pending. */
    case Tracking = 'tracking';

    /** The old thumbnail was put back. */
    case Reverted = 'reverted';

    /** The 28 day milestone is in: the verdict is final. */
    case Completed = 'completed';

    public function label(): TranslatableMessage
    {
        return new TranslatableMessage(match ($this) {
            self::Tracking => 'relaunch.status.tracking',
            self::Reverted => 'relaunch.status.reverted',
            self::Completed => 'relaunch.status.completed',
        });
    }
}
