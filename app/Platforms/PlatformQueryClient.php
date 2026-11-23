<?php

namespace App\Platforms;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
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

    private const NET_PROFIT_DEFAULTS = [
        'net_profit_field' => 'historyWinsLoses',
        'net_profit_unit' => 'cents',
        'net_profit_direction' => 'profit_positive',
    ];

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
            ...PlatformNetProfit::RULES,
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
            ...array_keys(PlatformNetProfit::RULES),
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

        $payload = $this->request($config, $config['endpoint'], [
            'queryType' => 'phone', 'queryValue' => $phone,
            'regionId' => (int) $config['region_id'], 'tenantId' => (int) $config['tenant_id'],
            'page' => 1, 'pageSize' => 50, 'order' => [['key' => '', 'type' => 'desc']],
        ]);

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

        $netProfitMapping = isset($config['net_profit_field']) ? $config : self::NET_PROFIT_DEFAULTS;
        $netProfit = PlatformNetProfit::fromAccountRow($account, $netProfitMapping, [
            'panda', $config['endpoint'], $config['admin_host'], (int) $config['tenant_id'], (int) $config['region_id'],
        ]);

        return new PlatformAccount((string) $id, $deposit, $validBet, now()->utc(), $netProfit);
    }

    public function rechargePage(
        #[SensitiveParameter] array $config,
        string $accountId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $page,
    ): PlatformRechargePage {
        $endpoint = $config['endpoint'] ?? '';
        $userId = $this->integer($accountId);
        if (! is_string($endpoint) || ! $this->acceptsEndpoint($endpoint) || $userId === null || $userId === 0) {
            throw new PlatformQueryFailure('configuration');
        }

        $region = (int) $config['region_id'];
        $tenant = (int) $config['tenant_id'];
        $url = substr($endpoint, 0, -strlen(self::QUERY_PATH)).'/api/backend/trpc/assetsChange.list';
        $payload = $this->request($config, $url, [
            'page' => $page, 'pageSize' => PlatformRechargePage::SIZE, 'changeTypes' => ['recharge'],
            'userId' => $userId, 'regionId' => $region, 'tenantId' => $tenant,
            'startTime' => $start->format('Y-m-d\TH:i:s.v\Z'), 'endTime' => $end->format('Y-m-d\TH:i:s.v\Z'),
        ]);
        $data = $payload['result']['data']['json'] ?? [];

        return PlatformRechargePage::fromProvider($data['pageData'] ?? null, $data['total'] ?? null, function (array $row) use ($userId, $region, $tenant): array {
            $time = $row['createTime'] ?? null;
            if ($this->integer($row['userId'] ?? null) !== $userId || $this->integer($row['regionId'] ?? null) !== $region
                || $this->integer($row['tenantId'] ?? null) !== $tenant || ($row['changeType'] ?? null) !== 'recharge'
                || ($row['currency'] ?? null) !== 'BRL' || ! is_string($time)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $time)) {
                throw new PlatformQueryFailure('schema');
            }
            try {
                $at = CarbonImmutable::parse($time);
            } catch (InvalidFormatException) {
                throw new PlatformQueryFailure('schema');
            }

            return PlatformRechargePage::record($row['id'] ?? null, $this->integer($row['amountChange'] ?? null), $at);
        });
    }

    private function request(#[SensitiveParameter] array $config, string $endpoint, array $data): array
    {
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
                ->get($endpoint, ['input' => json_encode(['json' => $data], JSON_THROW_ON_ERROR)]);
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
        if (! is_array($payload) || array_is_list($payload)) {
            throw new PlatformQueryFailure('schema');
        }

        return $payload;
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
