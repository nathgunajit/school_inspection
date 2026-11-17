<?php

namespace App\Services\Otp;

/**
 * SMS / OTP channel. Phase 1 uses LogOtpSender only (no OTP at registration).
 * Phase 2 binds a real SMS gateway implementation (PRD 5.4).
 */
interface OtpSender
{
    public function send(string $mobile, string $message): void;
}
