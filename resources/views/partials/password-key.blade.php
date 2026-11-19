{{-- V6 Module 5: public key and session nonce for encrypting password fields in the browser (resources/js/password-encrypt.js) --}}
@php $pwCrypto = app(\App\Services\PasswordCrypto::class); @endphp
@if ($pwCrypto->enabled())
    <meta name="pw-key" content="{{ $pwCrypto->publicKeySpki() }}" data-nonce="{{ $pwCrypto->nonce() }}">
@endif
