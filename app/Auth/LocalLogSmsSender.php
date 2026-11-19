<?php

namespace App\Auth;

use Illuminate\Support\Facades\Log;

class LocalLogSmsSender implements SmsSender
{
    public function sendCode(string $phone, string $purpose, string $code): void
    {
        Log::debug('Local SMS simulation', ['phone' => $phone, 'purpose' => $purpose, 'code' => $code]);
    }
}
