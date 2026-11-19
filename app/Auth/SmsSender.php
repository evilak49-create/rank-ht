<?php

namespace App\Auth;

interface SmsSender
{
    public function sendCode(string $phone, string $purpose, string $code): void;
}
