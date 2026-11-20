<?php

namespace App\Platforms;

use App\Auth\MemberPhone;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use SensitiveParameter;

class WgPlatformQuery implements PlatformQueryDriver
{
    private const PATH = '/api/foreign/openApi/site/user/memberApi/getAccountDetail';

    private const CIPHER = 'aes-128-ecb';

    public function validateConfiguration(#[SensitiveParameter] array $data): array
    {
        $settings = Validator::make($data, [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'site_code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'api_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'secret_key' => ['required', 'string', 'regex:/\A[^\x00-\x1F\x7F]{16}\z/'],
            'test_phone' => ['required', 'string'],
        ], ['secret_key.regex' => 'WG Secret Key 必须为 16 字节。'])->validate();

        $parts = parse_url($settings['endpoint']);
        if (in_array($parts['path'] ?? '', ['', '/'], true)) {
            $settings['endpoint'] = rtrim($settings['endpoint'], '/').self::PATH;
        }
        if (! $this->acceptsEndpoint($settings['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => '请输入 WG 服务器允许列表中的 HTTPS 域名或会员详情接口地址。']);
        }

        return $settings;
    }

    public function formState(#[SensitiveParameter] ?array $saved): array
    {
        $fields = array_fill_keys(['endpoint', 'site_code', 'api_key', 'secret_key', 'test_phone'], null);

        return Arr::only($saved ?? [], array_keys($fields)) + $fields;
    }

    public function lookupTestAccount(#[SensitiveParameter] array $config): ?PlatformAccount
    {
        $account = $config['test_phone'];
        $phone = MemberPhone::normalize($account);
        if (preg_match(substr(MemberPhone::RULE, strlen('regex:')), $phone)) {
            return $this->lookup($config, $phone) ?? $this->query($config, ['username' => $account]);
        }

        return $this->query($config, ['username' => $account]);
    }

    public function lookup(#[SensitiveParameter] array $config, string $phone): ?PlatformAccount
    {
        return $this->query($config, ['mobilePhone' => $phone]);
    }

    private function query(#[SensitiveParameter] array $config, array $filter): ?PlatformAccount
    {
        $endpoint = $config['endpoint'] ?? '';
        $key = $config['secret_key'] ?? '';
        if (! is_string($endpoint) || ! $this->acceptsEndpoint($endpoint) || ! is_string($key) || strlen($key) !== 16
            || ! is_string($config['api_key'] ?? null) || ! is_string($config['site_code'] ?? null)) {
            throw new PlatformQueryFailure('configuration');
        }

        $data = json_encode(['siteCode' => $config['site_code'], ...$filter], JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA);
        if ($ciphertext === false) {
            throw new PlatformQueryFailure('configuration');
        }
        $encrypted = base64_encode($ciphertext);
        $time = now()->timestamp;
        $apiKey = $config['api_key'];
        $signature = md5("apiKey={$apiKey}&reqData={$encrypted}&reqTime={$time}&secretKey={$key}");

        try {
            $response = Http::asJson()->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->post($endpoint, ['apiKey' => $apiKey, 'reqTime' => $time, 'reqData' => $encrypted, 'sign' => $signature]);
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

        return $this->account($this->decryptResponse($response->body(), $key), $config['site_code']);
    }

    private function acceptsEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);

        return ($url['scheme'] ?? null) === 'https'
            && in_array(strtolower($url['host'] ?? ''), config('services.wg_platform_query.allowed_hosts', []), true)
            && ($url['port'] ?? 443) === 443
            && ($url['path'] ?? null) === self::PATH
            && array_intersect_key($url, array_flip(['user', 'pass', 'query', 'fragment'])) === [];
    }

    private function decryptResponse(#[SensitiveParameter] string $response, #[SensitiveParameter] string $key): array
    {
        $envelope = $this->json($response);
        $code = $envelope['code'] ?? null;
        if (is_int($code) || is_string($code)) {
            if (in_array((string) $code, ['16001002', '16001003', '16001004', '16001005', '16001006', '16001007', '16001008'], true)) {
                throw new PlatformQueryFailure('authentication');
            }
            if ((string) $code === '16001000') {
                throw new PlatformQueryFailure('transient');
            }
        }
        if (! in_array($code, [0, '0'], true) || ($envelope['success'] ?? true) !== true || ! is_string($envelope['data'] ?? null)) {
            throw new PlatformQueryFailure('schema');
        }

        $ciphertext = base64_decode($envelope['data'], true);
        $plain = $ciphertext === false ? false : openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA);
        if ($plain === false) {
            throw new PlatformQueryFailure('schema');
        }

        return $this->json($plain);
    }

    private function account(#[SensitiveParameter] array $data, string $siteCode): ?PlatformAccount
    {
        if (! in_array($data['result'] ?? 0, [0, '0'], true)) {
            throw new PlatformQueryFailure('schema');
        }
        $rows = $data['list'] ?? null;
        $reportedTotal = $data['total'] ?? (is_array($rows) ? count($rows) : null);
        $total = is_int($reportedTotal) || is_string($reportedTotal)
            ? filter_var($reportedTotal, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if ($total === 0 && ($rows === [] || $rows === null)) {
            return null;
        }
        if (! is_array($rows) || ! array_is_list($rows) || $total === false) {
            throw new PlatformQueryFailure('schema');
        }
        if ($total > 1 || count($rows) > 1) {
            throw new PlatformQueryFailure('ambiguous');
        }
        if ($total !== 1 || count($rows) !== 1 || ! is_array($rows[0])) {
            throw new PlatformQueryFailure('schema');
        }
        $row = $rows[0];
        $id = $row['useridx'] ?? null;
        $reportedSite = $row['siteCode'] ?? $siteCode;
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]{0,39}\z/', (string) $id)
            || (! is_string($reportedSite) && ! is_int($reportedSite)) || (string) $reportedSite !== $siteCode
            || ($row['currency'] ?? null) !== 'BRL') {
            throw new PlatformQueryFailure('schema');
        }

        return new PlatformAccount((string) $id, $this->cents($row['totalDeposit'] ?? null), $this->cents($row['validBet'] ?? null), now()->utc());
    }

    private function json(#[SensitiveParameter] string $value): array
    {
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new PlatformQueryFailure('schema');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new PlatformQueryFailure('schema');
        }

        return $decoded;
    }

    private function cents(mixed $amount): int
    {
        if (is_float($amount) && is_finite($amount) && abs($amount) < 1_000_000_000_000) {
            // JSON numbers above this range must be strings to reliably preserve cents.
            $amount = json_encode($amount, JSON_THROW_ON_ERROR);
        } elseif (! is_int($amount) && (! is_string($amount) || ! preg_match('/\A[0-9]{1,24}(?:\.[0-9]{1,32})?\z/', $amount))) {
            throw new PlatformQueryFailure('schema');
        }

        try {
            $decimal = BigDecimal::of($amount);
            if (! $decimal->isNegative()) {
                return $decimal->multipliedBy(100)->toScale(0, RoundingMode::Down)->toInt();
            }
        } catch (MathException) {
            // A missing or invalid total is never treated as zero for settlement.
        }

        throw new PlatformQueryFailure('schema');
    }
}
