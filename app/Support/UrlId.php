<?php

namespace App\Support;

use RuntimeException;

/**
 * V6 Module 4 (URL encryption): turns a record ID into an opaque, tamper-proof URL token and back.
 *
 * token = base64url( iv[16] . AES-256-CBC(id as 8 bytes)[16] ), 43 characters, where
 * iv = first 16 bytes of HMAC-SHA256(macKey, "<Type>|<id>").
 *
 * - Deterministic: the same record always gets the same token, so links, bookmarks and caches stay stable.
 * - Tamper-proof: after decrypting, the IV is recomputed and compared; an edited token decodes to null.
 * - Typed: the model type is part of the MAC, so a School token cannot open an Inspection.
 * Both keys are derived from APP_KEY, so rotating APP_KEY changes every URL (old links return 404).
 */
final class UrlId
{
    private const CIPHER = 'aes-256-cbc';

    private static ?array $keys = null;

    public static function encode(string $type, int|string $id): string
    {
        $id = (int) $id;
        [$enc, $mac] = self::keys();
        $iv = substr(hash_hmac('sha256', self::type($type).'|'.$id, $mac, true), 0, 16);
        $ct = openssl_encrypt(pack('J', $id), self::CIPHER, $enc, OPENSSL_RAW_DATA, $iv);

        return rtrim(strtr(base64_encode($iv.$ct), '+/', '-_'), '=');
    }

    /** The ID inside the token, or null when the token is malformed, edited or belongs to another type. */
    public static function decode(string $type, ?string $token): ?int
    {
        if ($token === null || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/').'=', true);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }
        [$enc, $mac] = self::keys();
        $iv = substr($raw, 0, 16);
        $plain = openssl_decrypt(substr($raw, 16), self::CIPHER, $enc, OPENSSL_RAW_DATA, $iv);
        if ($plain === false || strlen($plain) !== 8) {
            return null;
        }
        $id = unpack('J', $plain)[1];
        if ($id < 1) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', self::type($type).'|'.$id, $mac, true), 0, 16);

        return hash_equals($expected, $iv) ? $id : null;
    }

    /** Clears the derived keys (tests that change APP_KEY). */
    public static function flush(): void
    {
        self::$keys = null;
    }

    private static function type(string $type): string
    {
        return class_basename($type);
    }

    private static function keys(): array
    {
        if (self::$keys === null) {
            $key = (string) config('app.key');
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7));
            }
            if ($key === '') {
                throw new RuntimeException('APP_KEY is not set; URL tokens cannot be generated.');
            }
            self::$keys = [hash_hmac('sha256', 'url-id-encryption', $key, true), hash_hmac('sha256', 'url-id-mac', $key, true)];
        }

        return self::$keys;
    }
}
