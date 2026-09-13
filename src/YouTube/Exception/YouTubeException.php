<?php

declare(strict_types=1);

namespace App\YouTube\Exception;

use App\Shared\Translation\TranslatableThrowable;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Base class of every failure coming from the YouTube APIs.
 *
 * The exception message stays a plain English string because it is written to
 * the log and to the job record; {@see getUserMessage()} carries the wording to
 * show the creator, which the view translates.
 */
class YouTubeException extends \RuntimeException implements TranslatableThrowable
{
    private ?TranslatableMessage $userMessage = null;

    public function getUserMessage(): TranslatableMessage
    {
        return $this->userMessage ?? new TranslatableMessage('youtube.error.generic');
    }

    public function withUserMessage(TranslatableMessage $message): static
    {
        $this->userMessage = $message;

        return $this;
    }

    /**
     * Same wording as {@see getUserMessage()}, under the name a failed job looks
     * for when it records why it stopped.
     */
    public function translatableMessage(): TranslatableInterface
    {
        return $this->getUserMessage();
    }
}
