<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformAutobindTest extends TestCase
{
    use RefreshDatabase;

    private bool $accountFound = true;

    public function test_verified_registration_click_enables_one_binding_with_account_snapshots(): void
    {
        [$member, $platform] = $this->fixture();
        $this->fakeLookup();

        $this->getJson($this->callbackUrl($member))->assertOk();
        Http::assertNothingSent();

        $this->launch($member, $platform)->assertOk()
            ->assertJsonPath('data.url', 'https://777ub.vip/?ch=86614&db_id=11912345678&bd_id=11912345678&platform=777PUB');
        $this->getJson($this->callbackUrl($member, 'Another'))->assertOk();
        Http::assertNothingSent();
        $this->getJson($this->callbackUrl($member))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->callbackUrl($member))->assertOk();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('platform_bindings', 1);
        $this->assertDatabaseHas('platform_bindings', [
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => '4000000001',
            'account_name' => $member->phone, 'query_phone' => $member->phone, 'baseline_deposit_cents' => 1_000, 'baseline_valid_bet_cents' => 2_000,
        ]);
        $this->assertSame(-325, $platform->bindings()->firstOrFail()->rescue_baseline['net_profit_cents']);
        $this->assertDatabaseHas('platform_binding_attempts', [
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => $member->phone,
            'status' => 'succeeded',
        ]);
        $binding = $platform->bindings()->firstOrFail();
        $this->getJson("/api/v1/platform-bindings/{$binding->id}")->assertOk()
            ->assertJsonPath('data.platform_account_id', $member->phone);
    }

    public function test_unsuccessful_partner_lookup_keeps_manual_binding_available_even_after_a_late_retry(): void
    {
        [$member, $platform] = $this->fixture();
        $this->accountFound = false;
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk();
        $this->getJson($this->callbackUrl($member))->assertOk();
        $this->assertDatabaseEmpty('platform_bindings');

        $this->travel(2)->hours();
        $this->getJson($this->callbackUrl($member))->assertOk();
        Http::assertSentCount(2);

        $this->accountFound = true;
        $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => $member->phone])->assertCreated()
            ->assertJsonPath('data.platform_account_id', '4000000001');
    }

    public function test_existing_automatic_bindings_keep_their_provider_id_when_backfilled(): void
    {
        [$member, $platform] = $this->fixture();
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk();
        $this->getJson($this->callbackUrl($member))->assertOk();

        $migration = require database_path('migrations/2026_11_26_000001_store_autobind_account_names.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('platform_bindings', [
            'platform_id' => $platform->id, 'member_id' => $member->id,
            'account_name' => $member->phone, 'platform_account_id' => '4000000001',
        ]);
        $this->assertDatabaseHas('platform_binding_attempts', [
            'platform_id' => $platform->id, 'member_id' => $member->id,
            'platform_account_id' => $member->phone,
        ]);
    }

    public function test_a_pending_registration_can_bind_after_the_member_signs_in_again(): void
    {
        [$member, $platform] = $this->fixture();
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk();
        DB::table('platform_registration_intents')->where('member_id', $member->id)->update(['session_version' => 0]);
        $this->postJson('/api/v1/auth/login', ['phone' => $member->phone, 'password' => 'member-password'])->assertOk();
        $this->assertSame(1, $member->refresh()->session_version);

        $this->getJson($this->callbackUrl($member))->assertOk();
        $this->assertDatabaseHas('platform_bindings', ['member_id' => $member->id, 'platform_id' => $platform->id]);
    }

    public function test_disabling_then_reenabling_an_account_does_not_restore_the_pending_registration(): void
    {
        [$member, $platform] = $this->fixture();
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk();
        $member->changeDisabledStatus(true);
        $member->changeDisabledStatus(false);

        $this->getJson($this->callbackUrl($member))->assertOk();
        Http::assertNothingSent();
        $this->assertDatabaseEmpty('platform_bindings');
    }

    public function test_an_unverified_member_can_bind_using_their_registration_phone(): void
    {
        [$member, $platform] = $this->fixture();
        $member->forceFill(['phone_verified_at' => null])->save();
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk()
            ->assertJsonPath('data.url', 'https://777ub.vip/?ch=86614&db_id=11912345678&bd_id=11912345678&platform=777PUB');
        $this->getJson($this->callbackUrl($member))->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('platform_bindings', [
            'member_id' => $member->id, 'platform_id' => $platform->id, 'query_phone' => $member->phone,
        ]);
    }

    public function test_a_bound_member_still_gets_the_agreed_parameters_without_a_new_intent(): void
    {
        [$member, $platform] = $this->fixture();
        $platform->update(['promotion_url' => 'https://777UB.PRO/?pid=example&fromPage=affiliate']);
        $this->fakeLookup();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0])
            ->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => $member->phone])->assertCreated();

        $url = $this->launch($member, $platform)->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'pid' => 'example', 'fromPage' => 'affiliate', 'db_id' => $member->phone, 'bd_id' => $member->phone, 'platform' => '777PUB',
        ], $query);
        $this->assertDatabaseEmpty('platform_registration_intents');
        $this->getJson($this->callbackUrl($member))->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('platform_bindings', 1);
    }

    public function test_a_platform_without_a_guarantee_uses_its_own_promotion_link_and_query_configuration(): void
    {
        [$member, $firstPlatform] = $this->fixture();
        $second = Platform::create([
            'name' => 'Second Site', 'promotion_url' => 'https://second.example.test/?ref=42',
            'rating' => 5, 'guarantee_amount_brl' => 0,
        ]);
        $second->forceFill(['active_query_revision' => 1, 'active_query' => array_replace($firstPlatform->active_query, [
            'tenant_id' => 42,
        ])])->save();
        $this->fakeLookup();

        $this->launch($member, $firstPlatform)->assertOk();
        $url = $this->launch($member, $second)->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertSame(['ref' => '42', 'db_id' => $member->phone, 'bd_id' => $member->phone, 'platform' => 'Second Site'], $parameters);
        $this->getJson($this->callbackUrl($member, $second->name))->assertOk();

        $this->assertDatabaseCount('platform_bindings', 1);
        $this->assertDatabaseHas('platform_bindings', ['member_id' => $member->id, 'platform_id' => $second->id]);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return json_decode($query['input'], true)['json']['tenantId'] === 42;
        });
    }

    private function fixture(): array
    {
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password', 'phone_verified_at' => now()]);
        $platform = Platform::create(['name' => '777PUB', 'promotion_url' => 'https://777ub.vip/?ch=86614', 'rating' => 5, 'guarantee_amount_brl' => 100]);
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => [
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'fixture', 'account' => 'fixture', 'fingerprint' => 'fixture',
            'region_id' => 1, 'tenant_id' => 10,
        ]])->save();

        return [$member, $platform];
    }

    private function launch(Member $member, Platform $platform)
    {
        return $this->actingAs($member, 'member')->withSession(['member_session_version' => 0])
            ->postJson("/api/v1/platforms/{$platform->id}/registration");
    }

    private function callbackUrl(Member $member, string $platform = '777PUB'): string
    {
        return '/api/v1/platform-autobind?'.http_build_query(['bd_id' => $member->phone, 'platform' => $platform]);
    }

    private function fakeLookup(): void
    {
        Http::fake(function () {
            $rows = $this->accountFound ? [[
                'id' => 4000000001, 'historicalPay' => 1_000, 'historicalValidBetting' => 2_000,
                'currency' => 'BRL', 'historyWinsLoses' => -325,
            ]] : [];

            return Http::response(['result' => ['data' => ['json' => ['userList' => ['total' => count($rows), 'pageData' => $rows]]]]]);
        });
    }
}
