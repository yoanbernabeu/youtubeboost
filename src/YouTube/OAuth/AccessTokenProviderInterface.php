<?php

declare(strict_types=1);

namespace App\YouTube\OAuth;

use App\YouTube\Exception\AuthorizationRequiredException;

interface AccessTokenProviderInterface
{
    /**
     * A valid access token for the connected channel, refreshed if needed.
     *
     * @throws AuthorizationRequiredException when no channel is connected or the refresh token died
     */
    public function getAccessToken(): string;

    /**
     * Forgets the cached token so the next call refreshes it.
     */
    public function invalidate(): void;
}
