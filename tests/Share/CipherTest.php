<?php

namespace Base\Office\Tests\Share;

use Base\Office\Exception\CorruptedException;
use Base\Office\Exception\KeyMissingException;
use Base\Office\Share\Cipher;
use PHPUnit\Framework\TestCase;

/** The vault's cipher: round trips, an altered file refused, no key no storage. */
final class CipherTest extends TestCase
{
    public function testAFileComesBackAsItWent(): void
    {
        $cipher = new Cipher(Cipher::generateKey());
        foreach ([0, 1, 1000, Cipher::CHUNK, Cipher::CHUNK + 1, 3 * Cipher::CHUNK] as $size) {
            $plain = $size ? random_bytes($size) : '';
            [$encrypted, $result] = $this->encrypt($cipher, $plain);

            self::assertSame($size, $result['size']);
            self::assertSame(hash('sha256', $plain), $result['sha256']);
            self::assertStringNotContainsString('%PDF', $encrypted);
            self::assertSame($plain, $this->decrypt($cipher, $encrypted, $result['wrappedKey']), "$size bytes");
        }
    }

    public function testNothingOfTheFileIsReadableOnceStored(): void
    {
        $cipher = new Cipher(Cipher::generateKey());
        [$encrypted] = $this->encrypt($cipher, str_repeat('Résultat : glycémie 1,02 g/L. ', 200));

        self::assertStringNotContainsString('glyc', $encrypted);
    }

    public function testAnAlteredFileIsRefused(): void
    {
        $cipher = new Cipher(Cipher::generateKey());
        [$encrypted, $result] = $this->encrypt($cipher, random_bytes(5000));
        $encrypted[100] = $encrypted[100] ^ "\x01";

        $this->expectException(CorruptedException::class);
        $this->decrypt($cipher, $encrypted, $result['wrappedKey']);
    }

    public function testATruncatedFileIsRefused(): void
    {
        $cipher = new Cipher(Cipher::generateKey());
        [$encrypted, $result] = $this->encrypt($cipher, random_bytes(2 * Cipher::CHUNK + 10));

        $this->expectException(CorruptedException::class);
        // The last chunk cut off cleanly: every remaining chunk authenticates, the final one is missing.
        $this->decrypt($cipher, substr($encrypted, 0, 24 + 2 * (Cipher::CHUNK + 17)), $result['wrappedKey']);
    }

    public function testAnotherMasterKeyDoesNotOpenTheFile(): void
    {
        [$encrypted, $result] = $this->encrypt(new Cipher(Cipher::generateKey()), 'secret');

        $this->expectException(CorruptedException::class);
        $this->decrypt(new Cipher(Cipher::generateKey()), $encrypted, $result['wrappedKey']);
    }

    public function testWithoutAKeyNothingIsStored(): void
    {
        foreach ([null, '', 'not-base64!', base64_encode('too short')] as $key) {
            $cipher = new Cipher($key);
            self::assertFalse($cipher->isConfigured());
            $out = fopen('php://memory', 'w+b');
            try {
                $cipher->encryptStream($this->stream('secret'), $out);
                self::fail('stored without a key');
            } catch (KeyMissingException) {
                rewind($out);
                self::assertSame('', stream_get_contents($out), 'not a byte written');
            }
            try {
                $cipher->encryptText('motif');
                self::fail('sealed without a key');
            } catch (KeyMissingException) {
                self::assertTrue(true);
            }
        }
    }

    public function testATextSealedAndOpened(): void
    {
        $cipher = new Cipher(Cipher::generateKey());
        $sealed = $cipher->encryptText('Renouvellement d’ordonnance');

        self::assertTrue($cipher->isSealed($sealed));
        self::assertStringNotContainsString('ordonnance', $sealed);
        self::assertNotSame($sealed, $cipher->encryptText('Renouvellement d’ordonnance'), 'a nonce each time');
        self::assertSame('Renouvellement d’ordonnance', $cipher->decryptText($sealed));
        self::assertNull($cipher->encryptText(null));
        self::assertNull($cipher->decryptText(null));

        $this->expectException(CorruptedException::class);
        $cipher->decryptText(substr($sealed, 0, -6).'AAAAAA');
    }

    /** @return array{0: string, 1: array{wrappedKey: string, sha256: string, size: int}} */
    private function encrypt(Cipher $cipher, string $plain): array
    {
        $out = fopen('php://memory', 'w+b');
        $result = $cipher->encryptStream($this->stream($plain), $out);
        rewind($out);

        return [stream_get_contents($out), $result];
    }

    private function decrypt(Cipher $cipher, string $encrypted, string $wrappedKey): string
    {
        $out = fopen('php://memory', 'w+b');
        $cipher->decryptStream($this->stream($encrypted), $out, $wrappedKey);
        rewind($out);

        return stream_get_contents($out);
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
