<?php

declare(strict_types=1);

namespace App\YouTube\Exception;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * The channel has to be connected again: no refresh token, or Google revoked it.
 *
 * An OAuth application left in "Testing" status expires refresh tokens after
 * seven days, which is the most common cause.
 */
final class AuthorizationRequiredException extends YouTubeException
{
    public static function notConnected(): self
    {
        return new self('No YouTube channel is connected.')
            ->withUserMessage(new TranslatableMessage('youtube.error.not_connected'));
    }

    public static function refreshFailed(string $reason): self
    {
        return new self(\sprintf('The YouTube authorisation has expired (%s).', $reason))
            ->withUserMessage(new TranslatableMessage('youtube.error.authorisation_expired', ['%reason%' => $reason]));
    }
}
