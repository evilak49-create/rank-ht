<?php

namespace App\Platforms;

interface PlatformQueryDriver
{
    /** Validate provider-specific fields and return settings, including a normalized test_phone. */
    public function validateConfiguration(array $data): array;

    public function formState(?array $saved): array;

    public function lookup(array $config, string $phone): ?PlatformAccount;
}
