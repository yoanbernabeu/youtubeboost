<?php

declare(strict_types=1);

namespace App\Shared\Translation;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * An exception that knows how to say itself in the creator's language.
 *
 * `getMessage()` stays English, because it is what lands in the log and what a
 * stack trace shows. This adds the version meant for the screen — the one that
 * ends up stored on a failed {@see \App\Job\Entity\Job}.
 */
interface TranslatableThrowable extends \Throwable
{
    public function translatableMessage(): TranslatableInterface;
}
