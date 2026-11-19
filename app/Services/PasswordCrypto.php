<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * V6 Module 5: passwords are encrypted in the browser before the form is submitted.
 *
 * - An RSA-2048 key pair lives on the private disk (storage/app/private/keys); it is created by
 *   `php artisan security:keys`, or on first use when missing.
 * - The page carries the public key (base64 SPKI) and a per-session nonce in <meta name="pw-key">.
 * - resources/js/password-encrypt.js sends each password field as "enc:" + base64(RSA-OAEP(JSON {p, n})).
 *   OAEP uses SHA-1, the only OAEP hash PHP's openssl_private_decrypt supports; it is still sound for encryption.
 * - DecryptPasswordFields turns the value back into the plain password before validation, so hashing (bcrypt)
 *   and the Form Requests are unchanged. A wrong nonce (another session) or a damaged value is refused.
 */
class PasswordCrypto
{
    public const PREFIX = 'enc:';

    public const FIELDS = ['password', 'password_confirmation', 'current_password'];

    private const SESSION_KEY = 'pw_nonce';

    private ?array $pair = null;

    public function enabled(): bool
    {
        return (bool) config('inspection.security.encrypt_passwords');
    }

    /** Plain-text passwords are refused (production default); load-test mode and tests accept them. */
    public function required(): bool
    {
        return $this->enabled() && config('inspection.security.require_encrypted_password') && ! config('inspection.loadtest');
    }

    /** Public key as base64 DER (SubjectPublicKeyInfo), the format WebCrypto imports as "spki". */
    public function publicKeySpki(): string
    {
        $pem = $this->keys()['public'];

        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /** The nonce of this session, created on first use. */
    public function nonce(): string
    {
        $session = session();
        if (! $session->has(self::SESSION_KEY)) {
            $session->put(self::SESSION_KEY, Str::random(32));
        }

        return $session->get(self::SESSION_KEY);
    }

    public function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** The plain password, or null when the value cannot be decrypted or belongs to another session. */
    public function decrypt(string $value): ?string
    {
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || $raw === '') {
            return null;
        }
        if (! openssl_private_decrypt($raw, $plain, $this->keys()['private'], OPENSSL_PKCS1_OAEP_PADDING)) {
            return null;
        }
        $data = json_decode($plain, true);
        if (! is_array($data) || ! is_string($data['p'] ?? null) || ! is_string($data['n'] ?? null)) {
            return null;
        }
        $nonce = session(self::SESSION_KEY);

        return is_string($nonce) && hash_equals($nonce, $data['n']) ? $data['p'] : null;
    }

    /** Encrypts like the browser does (tests and tools). */
    public function encryptForSession(string $password, ?string $nonce = null): string
    {
        $json = json_encode(['p' => $password, 'n' => $nonce ?? $this->nonce()]);
        if (! openssl_public_encrypt($json, $cipher, $this->keys()['public'], OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('Password encryption failed.');
        }

        return self::PREFIX.base64_encode($cipher);
    }

    /** Creates (or with $force replaces) the key pair. Returns the paths written. */
    public function generate(bool $force = false): array
    {
        $disk = Storage::disk('private');
        $dir = config('inspection.security.key_dir');
        if (! $force && $disk->exists("$dir/password.key") && $disk->exists("$dir/password.pub")) {
            return [];
        }
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $this->opensslConfig();
        $key = openssl_pkey_new($opts);
        if ($key === false || ! openssl_pkey_export($key, $private, null, $this->opensslConfig())) {
            throw new RuntimeException('Could not create the RSA key pair: '.openssl_error_string());
        }
        $public = openssl_pkey_get_details($key)['key'];
        $disk->put("$dir/password.key", $private);
        $disk->put("$dir/password.pub", $public);
        $this->pair = null;

        return [$disk->path("$dir/password.key"), $disk->path("$dir/password.pub")];
    }

    private function keys(): array
    {
        if ($this->pair === null) {
            $this->generate();
            $disk = Storage::disk('private');
            $dir = config('inspection.security.key_dir');
            $this->pair = ['private' => $disk->get("$dir/password.key"), 'public' => $disk->get("$dir/password.pub")];
        }

        return $this->pair;
    }

    /** On Windows/XAMPP no default openssl.cnf is found, so key generation gets a minimal one. */
    private function opensslConfig(): array
    {
        return getenv('OPENSSL_CONF') ? [] : ['config' => resource_path('openssl/minimal.cnf')];
    }
}
