<?php

namespace App\Platforms;

use App\Auth\MemberPhone;
use App\Points\CashbackSchedule;
use App\Storage\PublicHttps;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
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

    private const RECHARGE_PATH = '/api/foreign/openApi/proxy/user/finance/payOrder/queryByUser';

    private const MEMBER_DAILY_PATH = '/api/foreign/openApi/proxy/site/statistics/memberDaily';

    private const CIPHER = 'aes-128-ecb';

    public function validateConfiguration(#[SensitiveParameter] array $data): array
    {
        $settings = Validator::make($data, [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'site_code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'api_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'secret_key' => ['required', 'string', 'regex:/\A[^\x00-\x1F\x7F]{16}\z/'],
            'test_phone' => ['required', 'string'],
            ...PlatformNetProfit::RULES,
        ], ['secret_key.regex' => 'WG Secret Key 必须为 16 字节。'])->validate();

        $parts = parse_url($settings['endpoint']);
        if (in_array($parts['path'] ?? '', ['', '/'], true)) {
            $settings['endpoint'] = rtrim($settings['endpoint'], '/').self::PATH;
        }
        if (! $this->acceptsEndpoint($settings['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => '请输入 WG 提供的 HTTPS 域名或完整的会员详情接口地址。']);
        }

        return $settings;
    }

    public function formState(#[SensitiveParameter] ?array $saved): array
    {
        $fields = array_fill_keys(['endpoint', 'site_code', 'api_key', 'secret_key', 'test_phone', ...array_keys(PlatformNetProfit::RULES)], null);

        return Arr::only($saved ?? [], array_keys($fields)) + $fields;
    }

    public function lookupTestAccount(#[SensitiveParameter] array $config, ?Closure $onResponse = null): ?PlatformAccount
    {
        $account = $config['test_phone'];
        $phone = MemberPhone::normalize($account);
        if (preg_match(substr(MemberPhone::RULE, strlen('regex:')), $phone)) {
            return $this->query($config, ['mobilePhone' => $phone], $onResponse)
                ?? $this->query($config, ['username' => $account], $onResponse);
        }

        return $this->query($config, ['username' => $account], $onResponse);
    }

    public function lookup(#[SensitiveParameter] array $config, string $phone): ?PlatformAccount
    {
        return $this->query($config, ['mobilePhone' => $phone]);
    }

    public function lookupForRescue(#[SensitiveParameter] array $config, string $phone, string $boundId): ?PlatformAccount
    {
        $data = $this->signedRequest($config, self::PATH, ['mobilePhone' => $phone]);
        $account = $this->account($data, $config);
        if ($account === null || $account->id !== $boundId || $account->netProfit !== null) {
            return $account;
        }
        if (isset($config['net_profit_field'])) {
            throw new PlatformQueryFailure('net_profit_schema');
        }

        $registeredAt = filter_var($data['accountDetailInfo']['registerTime'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $userId = filter_var($account->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $end = $account->observedAt->toImmutable()->setTimezone(CashbackSchedule::TIMEZONE);
        if ($registeredAt === false || $userId === false || $registeredAt > $end->timestamp) {
            throw new PlatformQueryFailure('net_profit_schema');
        }

        $start = CarbonImmutable::createFromTimestamp($registeredAt, $end->getTimezone())->startOfDay();
        $amount = BigDecimal::zero();
        for ($from = $start; $from->lte($end); $from = $from->addDays(90)) {
            $until = $from->addDays(89)->endOfDay()->min($end);
            $amount = $amount->plus($this->memberDailyProfit($config, $userId, $from, $until));
        }

        $profit = PlatformNetProfit::fromAccountRow(['profitLose' => (string) $amount->toScale(2, RoundingMode::Down)], [
            'net_profit_field' => 'profitLose', 'net_profit_unit' => 'brl', 'net_profit_direction' => 'profit_positive',
        ], ['wg-member-daily', $config['endpoint'], $config['site_code'], $start->timestamp]);
        if ($profit === null) {
            throw new PlatformQueryFailure('net_profit_schema');
        }

        return new PlatformAccount($account->id, $account->depositCents, $account->validBetCents, $account->observedAt, $profit);
    }

    private function memberDailyProfit(
        #[SensitiveParameter] array $config, int $userId, CarbonImmutable $from, CarbonImmutable $until,
    ): BigDecimal {
        $filter = [
            'userIdx' => $userId, 'currency' => 'BRL', 'startTime' => $from->timestamp,
            'endTime' => $until->timestamp, 'pageSize' => 100,
        ];
        $amount = BigDecimal::zero();
        $days = [];
        $cursors = [];
        do {
            $response = $this->signedRequest($config, self::MEMBER_DAILY_PATH, $filter);
            $rows = $response['list'] ?? null;
            $hasMore = $response['hasMore'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || ! is_bool($hasMore)) {
                throw new PlatformQueryFailure('net_profit_schema');
            }
            foreach ($rows as $row) {
                $day = filter_var($row['createTime'] ?? null, FILTER_VALIDATE_INT);
                $profit = $row['profitLose'] ?? null;
                if (is_int($profit) || (is_float($profit) && is_finite($profit) && abs($profit) < 1_000_000_000_000)) {
                    $profit = (string) $profit;
                }
                if ((string) ($row['userIdx'] ?? '') !== (string) $userId || ($row['currency'] ?? null) !== 'BRL'
                    || $day === false || $day < $from->timestamp || $day > $until->timestamp || isset($days[$day])
                    || ! is_string($profit) || ! preg_match('/\A-?[0-9]{1,24}(?:\.[0-9]{1,32})?\z/', $profit)) {
                    throw new PlatformQueryFailure('net_profit_schema');
                }
                $days[$day] = true;
                $amount = $amount->plus($profit);
            }
            if ($hasMore) {
                $cursor = $response['cursor'] ?? null;
                if ($rows === [] || ! is_string($cursor) || $cursor === '' || isset($cursors[$cursor])) {
                    throw new PlatformQueryFailure('net_profit_schema');
                }
                $cursors[$cursor] = true;
                $filter['cursor'] = $cursor;
            }
        } while ($hasMore);

        return $amount;
    }

    public function rechargePage(
        #[SensitiveParameter] array $config,
        string $boundAccount,
        string $providerUserId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $page,
    ): PlatformRechargePage {
        $data = $this->signedRequest($config, self::RECHARGE_PATH, [
            'condition' => [
                'account' => $boundAccount, 'startSuccessTime' => $start->timestamp, 'endSuccessTime' => $end->timestamp,
                'payOrderType' => 2,
            ],
            'current' => $page, 'size' => PlatformRechargePage::SIZE,
        ]);
        if (! in_array($data['result'] ?? null, [0, '0'], true)) {
            throw new PlatformQueryFailure('schema');
        }

        return PlatformRechargePage::fromProvider($data['list'] ?? null, $data['total'] ?? null, function (array $row) use ($boundAccount, $providerUserId): ?array {
            $account = $row['account'] ?? null;
            $userId = $row['userId'] ?? $row['username'] ?? null;
            if (($account === null && $userId === null)
                || ($account !== null && $account !== $boundAccount)
                || ($userId !== null && ((! is_string($userId) && ! is_int($userId)) || (string) $userId !== $providerUserId))) {
                throw new PlatformQueryFailure('schema');
            }
            if (! in_array($row['orderStatus'] ?? null, [2, '2', 4, '4'], true)) {
                return null;
            }
            $time = $row['notifyTime'] ?? null;
            if (in_array($time, [null, 0, '0', ''], true)) {
                $time = $row['createTime'] ?? null;
            }
            if ((! is_int($time) && ! is_string($time)) || ! preg_match('/\A[0-9]{10}(?:[0-9]{3})?\z/', (string) $time)
                || ($row['payCurrency'] ?? $row['memberCurrency'] ?? null) !== 'BRL') {
                throw new PlatformQueryFailure('schema');
            }
            $at = CarbonImmutable::createFromTimestampUTC((int) substr((string) $time, 0, 10));

            return PlatformRechargePage::record($row['orderNo'] ?? null, $this->cents($row['amount'] ?? null), $at);
        });
    }

    private function query(#[SensitiveParameter] array $config, array $filter, ?Closure $onResponse = null): ?PlatformAccount
    {
        return $this->account($this->signedRequest($config, self::PATH, $filter, $onResponse), $config);
    }

    private function signedRequest(#[SensitiveParameter] array $config, string $path, array $filter, ?Closure $onResponse = null): array
    {
        $endpoint = $config['endpoint'] ?? '';
        $key = $config['secret_key'] ?? '';
        if (! is_string($endpoint) || ! $this->acceptsEndpoint($endpoint) || ! is_string($key) || strlen($key) !== 16
            || ! is_string($config['api_key'] ?? null) || ! is_string($config['site_code'] ?? null)) {
            throw new PlatformQueryFailure('configuration');
        }

        $url = substr($endpoint, 0, -strlen(self::PATH)).$path;
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
                ->setHandler(app(PublicHttps::class))
                ->post($url, ['apiKey' => $apiKey, 'reqTime' => $time, 'reqData' => $encrypted, 'sign' => $signature]);
        } catch (ConnectionException) {
            throw new PlatformQueryFailure('transient');
        } catch (DomainException) {
            throw new PlatformQueryFailure('public_endpoint');
        }

        if ($onResponse !== null) {
            $body = mb_scrub(str_replace([$key, $apiKey, $encrypted, $signature], '[已隐藏]', $response->body()));
            $onResponse($body, $response->status());
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

        return $this->decryptResponse($response->body(), $key);
    }

    private function acceptsEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);

        return PublicHttps::host($endpoint) !== null
            && ($url['path'] ?? null) === self::PATH
            && ! isset($url['query']) && ! isset($url['fragment']);
    }

    private function decryptResponse(#[SensitiveParameter] string $response, #[SensitiveParameter] string $key): array
    {
        $envelope = $this->json($response);
        $code = $envelope['code'] ?? null;
        if (is_int($code) || is_string($code)) {
            if (in_array((string) $code, ['16001004', '1116001004'], true)) {
                throw new PlatformQueryFailure('ip_not_allowed');
            }
            if (in_array((string) $code, ['16001002', '16001003', '16001005', '16001006', '16001007', '16001008'], true)) {
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

    private function account(#[SensitiveParameter] array $data, #[SensitiveParameter] array $config): ?PlatformAccount
    {
        $siteCode = $config['site_code'];
        if (array_key_exists('accountDetailInfo', $data)) {
            $detail = $data['accountDetailInfo'];
            if (! in_array($data['result'] ?? null, [0, '0'], true) || ! is_array($detail) || array_is_list($detail)) {
                throw new PlatformQueryFailure('schema');
            }

            $profit = PlatformNetProfit::fromAccountRow($detail, $config, ['wg-detail', $config['endpoint'], $siteCode]);

            return $this->accountFromRow([
                'useridx' => $detail['userIdx'] ?? null,
                'siteCode' => $detail['siteCode'] ?? null,
                // WG's account detail endpoint omits currency for the configured BRL site.
                'currency' => array_key_exists('currency', $detail) ? $detail['currency'] : 'BRL',
                'totalDeposit' => $detail['totalPayAmount'] ?? null,
                'validBet' => $detail['validBet'] ?? null,
            ], $siteCode, $profit);
        }

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

        $profit = PlatformNetProfit::fromAccountRow($rows[0], $config, ['wg-list', $config['endpoint'], $siteCode]);

        return $this->accountFromRow(['siteCode' => $rows[0]['siteCode'] ?? $siteCode] + $rows[0], $siteCode, $profit);
    }

    private function accountFromRow(#[SensitiveParameter] array $row, string $siteCode, ?PlatformNetProfit $profit): PlatformAccount
    {
        $id = $row['useridx'] ?? null;
        $reportedSite = $row['siteCode'] ?? null;
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]{0,39}\z/', (string) $id)
            || (! is_string($reportedSite) && ! is_int($reportedSite)) || (string) $reportedSite !== $siteCode
            || ($row['currency'] ?? null) !== 'BRL') {
            throw new PlatformQueryFailure('schema');
        }

        return new PlatformAccount((string) $id, $this->cents($row['totalDeposit'] ?? null), $this->cents($row['validBet'] ?? null), now()->utc(), $profit);
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
