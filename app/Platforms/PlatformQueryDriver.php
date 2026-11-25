<?php

namespace App\Platforms;

use Carbon\CarbonImmutable;
use Closure;

interface PlatformQueryDriver
{
    /** Validate provider-specific fields. The test account is stored under the legacy test_phone key. */
    public function validateConfiguration(array $data): array;

    public function formState(?array $saved): array;

    public function lookupTestAccount(array $config, ?Closure $onResponse = null): ?PlatformAccount;

    public function rechargePage(
        array $config, string $boundAccount, string $providerUserId, CarbonImmutable $start, CarbonImmutable $end, int $page,
    ): PlatformRechargePage;

    public function lookup(array $config, string $phone): ?PlatformAccount;
}
