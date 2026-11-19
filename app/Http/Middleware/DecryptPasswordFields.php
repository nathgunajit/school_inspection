<?php

namespace App\Http\Middleware;

use App\Services\PasswordCrypto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * V6 Module 5: replaces each RSA-encrypted password field ("enc:…", see PasswordCrypto) with the plain value
 * before the controller and its validation run. When encryption is required, a plain-text password is refused.
 * The plain value exists only in this request's memory; it is never flashed, logged or audited.
 */
class DecryptPasswordFields
{
    public function __construct(private PasswordCrypto $crypto) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->crypto->enabled() || $request->isMethodSafe()) {
            return $next($request);
        }

        $plain = [];
        foreach (PasswordCrypto::FIELDS as $field) {
            $value = $request->request->get($field);
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($this->crypto->isEncrypted($value)) {
                $decrypted = $this->crypto->decrypt($value);
                if ($decrypted === null) {
                    $this->refuse($field);
                }
                $plain[$field] = $decrypted;
            } elseif ($this->crypto->required()) {
                $this->refuse($field);
            }
        }

        if ($plain) {
            $request->request->add($plain);
            $request->merge($plain);
        }

        return $next($request);
    }

    private function refuse(string $field): never
    {
        throw ValidationException::withMessages([
            $field => 'Your password could not be sent securely. Please reload the page and try again.',
        ]);
    }
}
