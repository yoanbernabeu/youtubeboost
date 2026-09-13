<?php

declare(strict_types=1);

namespace App\Tests\Unit\YouTube\Security;

use App\YouTube\Security\TokenCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenCipher::class)]
final class TokenCipherTest extends TestCase
{
    public function testItRoundTripsASecret(): void
    {
        $cipher = new TokenCipher('an-application-secret');

        self::assertSame('1//04xYrefreshtoken', $cipher->decrypt($cipher->encrypt('1//04xYrefreshtoken')));
    }

    public function testTheCiphertextLooksNothingLikeThePlaintext(): void
    {
        $cipher = new TokenCipher('an-application-secret');

        $encrypted = $cipher->encrypt('1//04xYrefreshtoken');

        self::assertStringNotContainsString('refreshtoken', $encrypted);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]+={0,2}$#', $encrypted);
    }

    public function testTheSameSecretEncryptsDifferentlyEveryTime(): void
    {
        $cipher = new TokenCipher('an-application-secret');

        self::assertNotSame($cipher->encrypt('same'), $cipher->encrypt('same'));
    }

    public function testAnotherApplicationSecretCannotDecrypt(): void
    {
        $encrypted = new TokenCipher('first-secret')->encrypt('token');

        $this->expectException(\RuntimeException::class);

        new TokenCipher('second-secret')->decrypt($encrypted);
    }

    public function testATamperedCiphertextIsRejected(): void
    {
        $cipher = new TokenCipher('an-application-secret');
        $encrypted = $cipher->encrypt('token');

        $this->expectException(\RuntimeException::class);

        // Flip one byte in the middle of the ciphertext.
        $raw = (string) base64_decode($encrypted, true);
        $raw[20] = \chr(\ord($raw[20]) ^ 0xFF);

        $cipher->decrypt(base64_encode($raw));
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        new TokenCipher('an-application-secret')->decrypt('not base64 at all !!');
    }

    public function testAnEmptyApplicationSecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenCipher('');
    }
}
