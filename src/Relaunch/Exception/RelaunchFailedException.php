<?php

declare(strict_types=1);

namespace App\Relaunch\Exception;

use App\Shared\Translation\TranslatableThrowable;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class RelaunchFailedException extends \RuntimeException implements TranslatableThrowable
{
    private function __construct(
        string $message,
        private readonly TranslatableInterface $translatable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function proposalNotReady(): self
    {
        return new self(
            'This proposal has no usable image yet.',
            new TranslatableMessage('relaunch.error.proposal_not_ready'),
        );
    }

    public static function currentThumbnailUnavailable(): self
    {
        return new self(
            'The current thumbnail could not be downloaded; the relaunch is cancelled rather than lose the original.',
            new TranslatableMessage('relaunch.error.current_thumbnail_unavailable'),
        );
    }

    public static function uploadRefused(\Throwable $previous): self
    {
        return new self(
            \sprintf('YouTube refused the new thumbnail: %s', $previous->getMessage()),
            new TranslatableMessage('relaunch.error.upload_refused', ['%reason%' => $previous->getMessage()]),
            $previous,
        );
    }

    public static function alreadyReverted(): self
    {
        return new self(
            'This relaunch has already been rolled back.',
            new TranslatableMessage('relaunch.error.already_reverted'),
        );
    }

    public static function archiveMissing(): self
    {
        return new self(
            'The old thumbnail is no longer in the archive.',
            new TranslatableMessage('relaunch.error.archive_missing'),
        );
    }

    public function translatableMessage(): TranslatableInterface
    {
        return $this->translatable;
    }
}
