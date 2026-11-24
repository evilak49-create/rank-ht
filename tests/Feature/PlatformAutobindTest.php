<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->getJson($this->callbackUrl($member))->assertNoContent();
        Http::assertNothingSent();

        $this->launch($member, $platform)->assertOk()
            ->assertJsonPath('data.url', 'https://777ub.vip/?ch=86614&bd_id=11912345678&platform=777PUB');
        $this->getJson($this->callbackUrl($member, 'Another'))->assertNoContent();
        Http::assertNothingSent();
        $this->getJson($this->callbackUrl($member))->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->callbackUrl($member))->assertNoContent();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('platform_bindings', 1);
        $this->assertDatabaseHas('platform_bindings', [
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => '4000000001',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 1_000, 'baseline_valid_bet_cents' => 2_000,
        ]);
        $this->assertSame(-325, $platform->bindings()->firstOrFail()->rescue_baseline['net_profit_cents']);
    }

    public function test_a_missing_account_preserves_manual_binding_and_an_expired_click_cannot_bind(): void
    {
        [$member, $platform] = $this->fixture();
        $this->accountFound = false;
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk();
        $this->getJson($this->callbackUrl($member))->assertNoContent();
        $this->assertDatabaseEmpty('platform_bindings');

        $this->travel(2)->hours();
        $this->getJson($this->callbackUrl($member))->assertNoContent();
        Http::assertSentCount(1);

        $this->accountFound = true;
        $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => $member->phone])->assertCreated();
    }

    public function test_an_unverified_member_leaves_no_phone_in_the_outbound_url(): void
    {
        [$member, $platform] = $this->fixture();
        $member->forceFill(['phone_verified_at' => null])->save();
        $this->fakeLookup();
        $this->launch($member, $platform)->assertOk()->assertJsonPath('data.url', $platform->promotion_url);
        $this->getJson($this->callbackUrl($member))->assertNoContent();
        Http::assertNothingSent();
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
