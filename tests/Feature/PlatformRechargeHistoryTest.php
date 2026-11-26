<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Platforms\PlatformRecharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformRechargeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_panda_recharges_are_scoped_to_the_binding_and_upserted(): void
    {
        $this->travelTo(now()->utc()->setDate(2026, 11, 22)->setTime(10, 30));
        ['member' => $member, 'binding' => $binding] = $this->binding('panda', [
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'fixture', 'account' => 'fixture',
            'fingerprint' => 'fixture', 'region_id' => 1, 'tenant_id' => 17,
        ]);
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        Http::preventStrayRequests();
        Http::fake(['https://partner.example.test/api/backend/trpc/assetsChange.list*' => Http::response([
            'result' => ['data' => ['json' => ['total' => 51, 'pageData' => [[
                'id' => 5000000000, 'userId' => 4000000001, 'amountChange' => 12345, 'tenantId' => 17, 'regionId' => 1,
                'changeType' => 'recharge', 'createTime' => '2026-11-21T12:30:00.000Z', 'currency' => 'BRL',
                'remark' => 'private metadata',
            ]]]]],
        ])]);

        $path = "/api/v1/platform-bindings/{$binding->id}/recharges";
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($path.'?userId=123')->assertOk()->assertJsonPath('data.0.amount_cents', 12345)
            ->assertJsonPath('data.0.reference', '5000000000')->assertJsonPath('has_more', true)
            ->assertDontSee('private metadata');
        $this->getJson($path)->assertOk();
        $binding->platform->forceFill(['query_unhealthy_at' => now()])->save();
        $this->getJson($path)->assertNotFound();
        $adminPage = app(PlatformRecharges::class)->list($member, (string) $binding->id, 1, allowUnhealthy: true);
        $this->assertSame('5000000000', $adminPage['data'][0]['reference']);
        $this->assertDatabaseCount('platform_recharges', 1);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $data = json_decode($query['input'], true, flags: JSON_THROW_ON_ERROR)['json'];

            return $request->hasHeader('Authorization', 'Bearer fixture') && $data['userId'] === 4000000001
                && $data['tenantId'] === 17 && $data['changeTypes'] === ['recharge']
                && $data['startTime'] === '2026-10-22T10:30:00.000Z' && $data['endTime'] === '2026-11-22T10:30:00.000Z';
        });
        Http::assertSentCount(3);
    }

    public function test_a_member_cannot_access_another_binding_or_query_the_provider_for_it(): void
    {
        ['binding' => $binding] = $this->binding('panda', []);
        Http::fake();
        $other = Member::create(['phone' => '21912345678', 'password' => 'member-password']);
        $this->actingAs($other, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson("/api/v1/platform-bindings/{$binding->id}/recharges")->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseEmpty('platform_recharges');
    }

    public function test_wg_recharges_use_the_signed_single_member_endpoint_and_only_persist_paid_orders(): void
    {
        $this->travelTo(now()->utc()->setDate(2026, 11, 22)->setTime(10, 30));
        $secret = 'only-for-testing';
        ['member' => $member, 'binding' => $binding] = $this->binding('wg', [
            'endpoint' => 'https://wg.example.test/api/foreign/openApi/site/user/memberApi/getAccountDetail',
            'site_code' => '0025', 'api_key' => 'fixture', 'secret_key' => $secret,
        ]);
        $row = [
            'account' => $binding->query_phone, 'username' => 4000000001, 'orderNo' => 'deposit-1', 'orderStatus' => 4, 'amount' => '56.78',
            'payCurrency' => 'BRL', 'notifyTime' => now()->subDay()->getTimestampMs(),
        ];
        $body = fn (array $list) => Http::response([
            'code' => 0, 'success' => true, 'data' => base64_encode(openssl_encrypt(
                json_encode(['result' => 0, 'total' => count($list), 'list' => $list], JSON_THROW_ON_ERROR),
                'aes-128-ecb', $secret, OPENSSL_RAW_DATA,
            )),
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://wg.example.test/api/foreign/openApi/proxy/user/finance/payOrder/queryByUser' => Http::sequence()
            ->pushResponse($body([$row, [...$row, 'orderNo' => 'unpaid', 'orderStatus' => 1]]))
            ->pushResponse($body([[...$row, 'account' => '21912345678', 'username' => 4000000002]]))]);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $path = "/api/v1/platform-bindings/{$binding->id}/recharges";
        $this->getJson($path.'?page=2')->assertOk()->assertJsonPath('current_page', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount_cents', 5678)
            ->assertJsonPath('data.0.reference', 'deposit-1');
        $this->getJson($path)->assertServiceUnavailable();
        $this->assertDatabaseCount('platform_recharges', 1);
        Http::assertSent(function (Request $request) use ($secret, $binding): bool {
            $plain = openssl_decrypt(base64_decode($request['reqData'], true), 'aes-128-ecb', $secret, OPENSSL_RAW_DATA);
            $data = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);

            return $data['siteCode'] === '0025' && $data['condition']['account'] === $binding->query_phone
                && ! array_key_exists('userIds', $data['condition'])
                && $data['condition']['endSuccessTime'] === now()->timestamp
                && $data['condition']['payOrderType'] === 2 && $data['current'] === 2;
        });
    }

    private function binding(string $network, array $config): array
    {
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $platform = Platform::create(['name' => 'Recharge test', 'promotion_url' => 'https://example.test', 'rating' => 5, 'network' => $network]);
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => $config])->save();
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => '4000000001',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0,
            'baseline_observed_at' => now(),
        ]);

        return compact('member', 'binding');
    }
}
