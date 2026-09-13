<?php

declare(strict_types=1);

namespace App\Relaunch\Model;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * The blunt answer the creator is after: did the new thumbnail work?
 */
enum Verdict: string
{
    case Successful = 'successful';
    case Neutral = 'neutral';
    case Negative = 'negative';

    public function label(): TranslatableMessage
    {
        return new TranslatableMessage(match ($this) {
            self::Successful => 'relaunch.verdict.successful',
            self::Neutral => 'relaunch.verdict.neutral',
            self::Negative => 'relaunch.verdict.negative',
        });
    }

    public function suggestsRevert(): bool
    {
        return self::Negative === $this;
    }
}
