<?php

declare(strict_types=1);

namespace App\YouTube\Exception;

use App\YouTube\Quota\QuotaSnapshot;
use Symfony\Component\Translation\TranslatableMessage;

final class QuotaExhaustedException extends YouTubeException
{
    public static function forCost(int $cost, QuotaSnapshot $snapshot): self
    {
        $resetsAt = $snapshot->resetsAt->format('d/m/Y H:i');

        return new self(\sprintf(
            'This operation costs %d quota units but only %d are left today. The quota resets on %s.',
            $cost,
            $snapshot->remaining(),
            $resetsAt,
        ))->withUserMessage(new TranslatableMessage('youtube.error.quota_for_cost', [
            '%cost%' => $cost,
            '%remaining%' => $snapshot->remaining(),
            '%date%' => $resetsAt,
        ]));
    }
}
