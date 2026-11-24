<?php

namespace App\RedPackets;

use App\Models\PixWithdrawal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class ExternalPixChannel
{
    public const PROVIDERS = [
        'u2cpay' => [
            'label' => 'U2Cpay', 'host' => 'gateway.novavexis.com',
            'create' => '/api/open/merchant/payment/create', 'query' => '/api/open/merchant/payment/query',
            'balance' => '/api/open/merchant/balance/query',
            'ips' => '34.41.185.92 34.59.21.204 104.197.83.218 35.223.151.137 34.122.32.196 34.56.70.140 35.224.215.26 34.56.91.65 34.16.3.97 34.72.99.136 34.57.17.247 34.45.242.132',
        ],
        '4zpay' => [
            'label' => '4zpay', 'host' => 'gw.infyric.com',
            'create' => '/api/open/flex/order/payment/add', 'query' => '/api/open/flex/order/payment/get',
            'balance' => '/api/open/flex/account/balance/get',
            'ips' => '34.151.244.142 35.247.254.113 34.151.211.73 34.151.249.49 34.95.207.120 35.199.116.129',
        ],
    ];

    public static function validateConfiguration(#[SensitiveParameter] array $data): array
    {
        $configuration = Validator::make($data, [
            'driver' => ['required', Rule::in(array_keys(self::PROVIDERS))],
            'merchant' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'secret' => ['required', 'string', 'max:255', 'not_regex:/[[:cntrl:]]/'],
            'create' => ['required', 'url:https', 'max:512'],
            'query' => ['required', 'url:https', 'max:512'],
            'balance' => ['required', 'url:https', 'max:512'],
            'callback_ips' => ['required', 'array', 'max:64'],
            'callback_ips.*' => ['required', 'ip'],
            'min_cents' => ['nullable', 'integer', 'min:1'],
            'max_cents' => ['nullable', 'integer', 'min:1', Rule::when(isset($data['min_cents']), 'gte:min_cents')],
        ])->validate();

        foreach (['create', 'query', 'balance'] as $field) {
            if (! self::validEndpoint($configuration['driver'], $configuration[$field])) {
                throw ValidationException::withMessages([$field => '请输入该渠道官方网关的 HTTPS API 地址。']);
            }
        }

        return $configuration;
    }

    private static function validEndpoint(string $driver, string $url): bool
    {
        $parts = parse_url($url);

        return isset(self::PROVIDERS[$driver]) && ($parts['scheme'] ?? null) === 'https'
            && strtolower($parts['host'] ?? '') === self::PROVIDERS[$driver]['host']
            && ($parts['port'] ?? 443) === 443 && str_starts_with($parts['path'] ?? '', '/api/open/')
            && array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment'])) === [];
    }

    public function send(PixWithdrawal $order): PixResult
    {
        $config = $order->payout_configuration;
        $accountKey = $config['driver'] === '4zpay' ? 'account' : 'accountNo';
        $response = $this->request($config, 'create', $this->identifiers($order) + [
            'amount' => $order->amount_cents,
            'currency' => 'BRL',
            'accountType' => 'PIX_CPF',
            $accountKey => $order->cpf_snapshot,
            'accountName' => $config['recipient_name'],
            'callback' => route('pix.provider-callback', ['channel' => $order->payout_channel_id]),
        ]);

        if ($order->payout_number === null || $response === []) {
            return new PixResult('unknown');
        }

        // Create responses can acknowledge submission, but the signed callback owns settlement.
        return new PixResult('submitted', $this->reference($response));
    }

    public function lookup(PixWithdrawal $order): PixResult
    {
        $data = $this->request($order->payout_configuration, 'query', $this->identifiers($order));

        return $this->result($order, $data) ?? new PixResult('unknown');
    }

    public function balance(#[SensitiveParameter] array $config): ?int
    {
        $data = $this->request($config, 'balance', [$this->merchantField($config['driver']) => $config['merchant']]);
        foreach ($data as $row) {
            if (is_array($row) && ($row['currency'] ?? null) === 'BRL') {
                return filter_var($row['balance'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            }
        }

        return null;
    }

    public function authenticateCallback(PixWithdrawal $order, #[SensitiveParameter] array $data, string $ip): bool
    {
        $config = $order->payout_configuration;
        if ($config === null || ! in_array($ip, $config['callback_ips'], true) || ! is_string($data['sign'] ?? null)) {
            return false;
        }

        $signature = $data['sign'];
        unset($data['sign']);
        foreach ($data as $value) {
            if ($value !== null && ! is_string($value) && ! is_int($value)) {
                return false;
            }
        }

        return hash_equals(self::signature($data, $config['secret']), $signature);
    }

    public function result(PixWithdrawal $order, #[SensitiveParameter] array $data): ?PixResult
    {
        foreach ($this->identifiers($order) as $field => $expected) {
            $actual = $data[$field] ?? null;
            if ((! is_string($actual) && ! is_int($actual)) || (string) $actual !== $expected) {
                return null;
            }
        }
        $amount = $data['amount'] ?? null;
        if ((! is_int($amount) && ! is_string($amount)) || (string) $amount !== (string) $order->amount_cents
            || ($data['currency'] ?? null) !== 'BRL') {
            return null;
        }
        $reference = $this->reference($data);
        if ($reference === null) {
            return null;
        }

        return match ($data['status'] ?? null) {
            'SUCCESS' => new PixResult('paid', $reference),
            'FAILED', 'REJECT' => new PixResult('failed', $reference),
            'TOBE_AUDIT', 'PAYING' => new PixResult('unknown', $reference),
            default => null,
        };
    }

    private function reference(#[SensitiveParameter] array $data): ?string
    {
        $reference = $data['orderNo'] ?? null;
        if ((! is_string($reference) && ! is_int($reference)) || trim((string) $reference) === '' || strlen((string) $reference) > 190) {
            return null;
        }

        return (string) $reference;
    }

    public static function signature(#[SensitiveParameter] array $fields, #[SensitiveParameter] string $secret): string
    {
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key.'='.$value;
            }
        }

        return md5(implode('&', $parts).'&secret='.$secret);
    }

    private function request(#[SensitiveParameter] array $config, string $operation, #[SensitiveParameter] array $data): array
    {
        if (! self::validEndpoint($config['driver'], $config[$operation])) {
            return [];
        }
        $response = Http::acceptJson()->asJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
            ->post($config[$operation], $data + ['sign' => self::signature($data, $config['secret'])]);
        $body = $response->json();

        return $response->successful() && ($body['success'] ?? null) === true && is_array($body['data'] ?? null)
            ? $body['data'] : [];
    }

    private function identifiers(PixWithdrawal $order): array
    {
        $config = $order->payout_configuration;

        return [$this->merchantField($config['driver']) => $config['merchant'], 'merchantOrderNo' => $order->order_number];
    }

    private function merchantField(string $driver): string
    {
        return $driver === '4zpay' ? 'merchantNo' : 'merchantId';
    }
}
