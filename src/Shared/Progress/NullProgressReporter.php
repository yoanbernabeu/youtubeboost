<?php

declare(strict_types=1);

namespace App\Shared\Progress;

use Symfony\Contracts\Translation\TranslatableInterface;

final class NullProgressReporter implements ProgressReporterInterface
{
    public function step(string|TranslatableInterface $label): void
    {
    }

    public function progress(int $done, int $total, string|TranslatableInterface|null $label = null): void
    {
    }
}
