<?php

declare(strict_types=1);

namespace App\YouTube\OAuth;

/**
 * Proof Key for Code Exchange pair.
 *
 * Google only recommends PKCE for confidential web clients, but it costs a few
 * lines and removes a whole class of authorization-code interception.
 */
final readonly class PkcePair
{
    private function __construct(
        public string $verifier,
        public string $challenge,
    ) {
    }

    public static function generate(): self
    {
        $verifier = self::base64Url(random_bytes(48));

        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    public static function fromVerifier(string $verifier): self
    {
        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
