<?php

namespace App\Platforms;

use Carbon\CarbonImmutable;

interface PlatformQueryDriver
{
    /** Validate provider-specific fields. The test account is stored under the legacy test_phone key. */
    public function validateConfiguration(array $data): array;

    public function formState(?array $saved): array;

    public function lookupTestAccount(array $config): ?PlatformAccount;

    public function rechargePage(array $config, string $accountId, CarbonImmutable $start, CarbonImmutable $end, int $page): PlatformRechargePage;

    public function lookup(array $config, string $phone): ?PlatformAccount;
}
