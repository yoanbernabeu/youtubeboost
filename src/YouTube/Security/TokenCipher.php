<?php

declare(strict_types=1);

namespace App\YouTube\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric encryption of the Google refresh token before it reaches the database.
 *
 * The key is derived from the application secret, so a database dump alone is
 * not enough to take over the creator's channel.
 */
final class TokenCipher
{
    private readonly string $key;

    public function __construct(
        #[Autowire(env: 'APP_SECRET')]
        string $applicationSecret,
    ) {
        if ('' === $applicationSecret) {
            throw new \InvalidArgumentException('APP_SECRET must be set to encrypt the Google refresh token.');
        }

        $this->key = sodium_crypto_generichash($applicationSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * @throws \RuntimeException when the payload was not produced by this key
     */
    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('The stored token is not a valid encrypted payload.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);

        if (false === $plaintext) {
            throw new \RuntimeException('The stored token could not be decrypted with the current APP_SECRET.');
        }

        return $plaintext;
    }
}
