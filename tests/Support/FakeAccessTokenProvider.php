<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\YouTube\OAuth\AccessTokenProviderInterface;

final class FakeAccessTokenProvider implements AccessTokenProviderInterface
{
    public bool $invalidated = false;

    public function __construct(private readonly string $token = 'ya29.test-token')
    {
    }

    public function getAccessToken(): string
    {
        return $this->token;
    }

    public function invalidate(): void
    {
        $this->invalidated = true;
    }
}
