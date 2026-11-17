<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

class LogOtpSender implements OtpSender
{
    public function send(string $mobile, string $message): void
    {
        Log::info('[SMS] to '.$mobile.': '.$message);
    }
}
