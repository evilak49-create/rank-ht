<?php

namespace App\Platforms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use SensitiveParameter;

class PlatformQueryClient implements PlatformQueryDriver
{
    private const QUERY_PATH = '/api/backend/trpc/user.list';

    public function validateConfiguration(#[SensitiveParameter] array $data): array
    {
        if (is_string($data['endpoint'] ?? null)) {
            // The request parameters are generated from the saved settings and lookup phone.
            $data['endpoint'] = preg_replace('/\?[^#]*/', '', trim($data['endpoint']));
        }
        if (is_string($data['token'] ?? null)) {
            $data['token'] = preg_replace('/\ABearer +/i', '', trim($data['token']));
        }
        $data['client_language'] ??= config('services.platform_query.defaults.client_language', 'zh-CN');
        $configuration = Validator::make($data, [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'admin_host' => ['required', 'string', 'max:253', 'regex:/\A[a-zA-Z0-9.-]+\z/'],
            'token' => ['required', 'string', 'max:4096', 'not_regex:/[[:cntrl:]]/'],
            'account' => ['required', 'string', 'max:255', 'not_regex:/[[:cntrl:]]/'],
            'fingerprint' => ['required', 'string', 'max:255', 'not_regex:/[[:cntrl:]]/'],
            'tenant_id' => 'required|integer|min:1',
            'region_id' => 'required|integer|min:0',
            'client_language' => ['required', 'string', 'max:24', 'regex:/\A[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{2,8})*\z/'],
            'test_phone' => ['required', 'string'],
        ])->validate();

        if (! $this->acceptsEndpoint($configuration['endpoint'])) {
            throw ValidationException::withMessages([
                'endpoint' => '请输入允许列表中的 HTTPS 查询端点，路径须为 /api/backend/trpc/user.list。',
            ]);
        }

        return $configuration;
    }

    public function formState(#[SensitiveParameter] ?array $saved): array
    {
        $defaults = config('services.platform_query.defaults', []);

        return Arr::only($saved === null ? $defaults : $saved + ['client_language' => $defaults['client_language'] ?? 'zh-CN'], [
            'endpoint', 'admin_host', 'token', 'account', 'fingerprint', 'tenant_id', 'region_id', 'test_phone', 'client_language',
        ]);
    }

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

    public function lookupTestAccount(#[SensitiveParameter] array $config): ?PlatformAccount
    {
        return $this->lookup($config, $config['test_phone']);
    }

    public function lookup(#[SensitiveParameter] array $config, string $phone): ?PlatformAccount
    {
        if (! $this->acceptsEndpoint($config['endpoint'])) {
            throw new PlatformQueryFailure('configuration');
        }

        try {
            $response = Http::acceptJson()->withToken($config['token'])
                ->withHeaders([
                    'account' => $config['account'],
                    'fingerprint-id' => $config['fingerprint'],
                    'client-language' => $config['client_language'] ?? 'zh-CN',
                    'Content-Type' => 'application/json',
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
