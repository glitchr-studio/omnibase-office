<?php

namespace Base\Office\Share;

use Base\Office\Exception\CorruptedException;
use Base\Office\Exception\KeyMissingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * libsodium, and nothing else: a file is encrypted with a key of its own
 * (crypto_secretstream_xchacha20poly1305, chunk by chunk, authenticated -
 * a byte changed or a chunk missing and it does not decrypt); that key is
 * kept wrapped by a key derived from the master key (crypto_secretbox).
 * Short texts - a title, a file name, an appointment's reason, a message -
 * are sealed with another derived key.
 *
 * Fail closed: without a master key (32 bytes, base64, from the secrets
 * vault) every call throws KeyMissingException. Nothing is ever stored in
 * clear as a fallback - unlike omnibase's #[Vault], which does.
 */
class Cipher
{
    public const CHUNK = 65536;
    private const CONTEXT = 'office__';
    private const WRAP_KEY_ID = 1;
    private const TEXT_KEY_ID = 2;
    private const TEXT_PREFIX = 'v1:';

    private ?string $master = null;
    private bool $checked = false;

    private readonly ?string $masterKey;

    public function __construct(#[Autowire('%office.share.master_key%')] #[\SensitiveParameter] ?string $masterKey = null)
    {
        $this->masterKey = $masterKey;
    }

    /** A new master key, base64: what `secrets:set SHARE_MASTER_KEY` should be given. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function isConfigured(): bool
    {
        try {
            $this->master();

            return true;
        } catch (KeyMissingException) {
            return false;
        }
    }

    /**
     * Encrypts $in to $out.
     *
     * @param resource $in
     * @param resource $out
     *
     * @return array{wrappedKey: string, sha256: string, size: int}
     */
    public function encryptStream($in, $out): array
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $wrapped = $this->wrap($key);
        fwrite($out, $header);

        $hash = hash_init('sha256');
        $size = 0;
        $chunk = self::read($in);
        do {
            $next = feof($in) ? '' : self::read($in);
            $final = '' === $next;
            hash_update($hash, $chunk);
            $size += \strlen($chunk);
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                '',
                $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));
            $chunk = $next;
        } while (!$final);
        sodium_memzero($key);

        return ['wrappedKey' => $wrapped, 'sha256' => hash_final($hash), 'size' => $size];
    }

    /**
     * Decrypts $in to $out with the file's wrapped key; throws when a chunk
     * does not authenticate, or the stream ends before its final chunk.
     *
     * @param resource $in
     * @param resource $out
     *
     * @return string the SHA-256 of what was written
     */
    public function decryptStream($in, $out, string $wrappedKey): string
    {
        $key = $this->unwrap($wrappedKey);
        $header = self::readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES !== \strlen($header)) {
            throw new CorruptedException('The file is too short to be one of the vault\'s.');
        }
        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        } catch (\SodiumException $e) {
            throw new CorruptedException('The file\'s header is not readable.', 0, $e);
        }
        sodium_memzero($key);

        $hash = hash_init('sha256');
        $final = false;
        while (!$final) {
            $block = self::readExactly($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ('' === $block) {
                throw new CorruptedException('The file ends before its last chunk: truncated.');
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
            if (false === $result) {
                throw new CorruptedException('A chunk of the file does not authenticate: altered, or another key\'s.');
            }
            [$plain, $tag] = $result;
            hash_update($hash, $plain);
            fwrite($out, $plain);
            $final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag;
        }
        if (!feof($in) && '' !== fread($in, 1)) {
            throw new CorruptedException('Bytes follow the file\'s last chunk.');
        }

        return hash_final($hash);
    }

    /** A short text sealed: "v1:" + base64(nonce + box). Null stays null. */
    public function encryptText(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::TEXT_PREFIX.base64_encode($nonce.sodium_crypto_secretbox($text, $nonce, $this->derive(self::TEXT_KEY_ID)));
    }

    public function decryptText(?string $cipher): ?string
    {
        if (null === $cipher || '' === $cipher) {
            return $cipher;
        }
        if (!str_starts_with($cipher, self::TEXT_PREFIX)) {
            throw new CorruptedException('Not a sealed text.');
        }
        $raw = base64_decode(substr($cipher, \strlen(self::TEXT_PREFIX)), true);
        if (false === $raw || \strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new CorruptedException('A sealed text that is too short.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->derive(self::TEXT_KEY_ID));
        if (false === $plain) {
            throw new CorruptedException('A sealed text that does not open: altered, or another key\'s.');
        }

        return $plain;
    }

    public function isSealed(?string $value): bool
    {
        return null !== $value && str_starts_with($value, self::TEXT_PREFIX);
    }

    private function wrap(string $fileKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($fileKey, $nonce, $this->derive(self::WRAP_KEY_ID)));
    }

    private function unwrap(string $wrapped): string
    {
        $raw = base64_decode($wrapped, true);
        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new CorruptedException('The file\'s key is not readable.');
        }
        $key = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->derive(self::WRAP_KEY_ID));
        if (false === $key) {
            throw new CorruptedException('The file\'s key does not open with this master key.');
        }

        return $key;
    }

    private function derive(int $id): string
    {
        return sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $id, self::CONTEXT, $this->master());
    }

    private function master(): string
    {
        if (!$this->checked) {
            $this->checked = true;
            $raw = null !== $this->masterKey ? base64_decode(trim($this->masterKey), true) : false;
            $this->master = false !== $raw && SODIUM_CRYPTO_KDF_KEYBYTES === \strlen($raw) ? $raw : null;
        }

        return $this->master ?? throw new KeyMissingException('No usable SHARE_MASTER_KEY (base64 of 32 bytes): the vault stores and reads nothing without it.');
    }

    /** @param resource $in */
    private static function read($in): string
    {
        return self::readExactly($in, self::CHUNK);
    }

    /** @param resource $in */
    private static function readExactly($in, int $length): string
    {
        $data = '';
        while (\strlen($data) < $length && !feof($in)) {
            $piece = fread($in, $length - \strlen($data));
            if (false === $piece || '' === $piece) {
                break;
            }
            $data .= $piece;
        }

        return $data;
    }
}
