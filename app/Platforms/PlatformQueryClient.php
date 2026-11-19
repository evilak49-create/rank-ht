<?php

namespace App\Platforms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class PlatformQueryClient
{
    private const QUERY_PATH = '/api/backend/trpc/user.list';

    public function acceptsEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? null) === 'https'
            && in_array($host, config('services.platform_query.allowed_hosts', []), true)
            && ($parts['port'] ?? 443) === 443
            && ($parts['path'] ?? null) === self::QUERY_PATH
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts);
    }

    public function lookup(array $config, string $phone): ?PlatformAccount
    {
        if (! $this->acceptsEndpoint($config['endpoint'])) {
            throw new PlatformQueryFailure('configuration');
        }

        try {
            $response = Http::acceptJson()->withToken($config['token'])
                ->withHeaders([
                    'account' => $config['account'],
                    'fingerprint-id' => $config['fingerprint'],
                    'Origin' => 'https://'.$config['admin_host'],
                    'Referer' => 'https://'.$config['admin_host'].'/',
                    'x-admin-host' => $config['admin_host'],
                ])
                ->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->get($config['endpoint'], ['input' => json_encode(['json' => [
                    'queryType' => 'phone', 'queryValue' => $phone,
                    'regionId' => (int) $config['region_id'], 'tenantId' => (int) $config['tenant_id'],
                    'page' => 1, 'pageSize' => 50, 'order' => [['key' => '', 'type' => 'desc']],
                ]], JSON_THROW_ON_ERROR)]);
        } catch (ConnectionException) {
            throw new PlatformQueryFailure('transient');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new PlatformQueryFailure('authentication');
        }

        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            throw new PlatformQueryFailure('transient');
        }

        if (! $response->successful()) {
            throw new PlatformQueryFailure('schema');
        }

        try {
            $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new PlatformQueryFailure('schema');
        }

        $list = $payload['result']['data']['json']['userList'] ?? null;
        $total = $this->integer($list['total'] ?? null);
        $rows = $list['pageData'] ?? null;
        if ($total === null || ! is_array($rows) || ! array_is_list($rows)) {
            throw new PlatformQueryFailure('schema');
        }

        if ($total === 0 && $rows === []) {
            return null;
        }

        if ($total > 1 && count($rows) >= 2 && count($rows) <= $total) {
            throw new PlatformQueryFailure('ambiguous');
        }

        if ($total !== 1 || count($rows) !== 1 || ! is_array($rows[0])) {
            throw new PlatformQueryFailure('schema');
        }

        $account = $rows[0];
        $id = $account['id'] ?? null;
        $deposit = $this->integer($account['historicalPay'] ?? null);
        $validBet = $this->integer($account['historicalValidBetting'] ?? null);

        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]{0,39}\z/', (string) $id)
            || $deposit === null || $validBet === null || ($account['currency'] ?? null) !== 'BRL') {
            throw new PlatformQueryFailure('schema');
        }

        return new PlatformAccount((string) $id, $deposit, $validBet, now()->utc());
    }

    private function integer(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $number === false ? null : $number;
    }
}
