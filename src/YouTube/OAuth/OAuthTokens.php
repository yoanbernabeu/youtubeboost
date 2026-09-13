<?php

declare(strict_types=1);

namespace App\YouTube\OAuth;

/**
 * The tokens Google hands back, normalised.
 */
final readonly class OAuthTokens
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $accessToken,
        public \DateTimeImmutable $expiresAt,
        /** Only present on the very first grant, or after a forced consent. */
        public ?string $refreshToken,
        public array $scopes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the decoded token endpoint response
     */
    public static function fromTokenResponse(array $payload, \DateTimeImmutable $now): self
    {
        $accessToken = $payload['access_token'] ?? null;
        if (!\is_string($accessToken) || '' === $accessToken) {
            throw new \InvalidArgumentException('The Google token response carries no access token.');
        }

        $expiresIn = isset($payload['expires_in']) && is_numeric($payload['expires_in']) ? (int) $payload['expires_in'] : 3600;
        $refreshToken = $payload['refresh_token'] ?? null;
        $scope = $payload['scope'] ?? '';

        return new self(
            $accessToken,
            $now->modify(\sprintf('+%d seconds', $expiresIn)),
            \is_string($refreshToken) && '' !== $refreshToken ? $refreshToken : null,
            \is_string($scope) ? array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => '' !== $s)) : [],
        );
    }
}
