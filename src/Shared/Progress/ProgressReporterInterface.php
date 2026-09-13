<?php

declare(strict_types=1);

namespace App\Shared\Progress;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Lets a long-running service report where it is without knowing about jobs.
 *
 * Labels are shown to the creator, so they arrive as translatable messages; a
 * plain string is still accepted for a label that is already a piece of data,
 * such as the title of the video being processed.
 */
interface ProgressReporterInterface
{
    public function step(string|TranslatableInterface $label): void;

    public function progress(int $done, int $total, string|TranslatableInterface|null $label = null): void;
}
