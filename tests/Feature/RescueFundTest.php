<?php

namespace Tests\Feature;

use App\Filament\Pages\RescueFundConfiguration;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointRule;
use App\Models\User;
use App\Platforms\BindPlatformAccount;
use App\Platforms\PlatformAdministration;
use App\Platforms\PlatformQueryClient;
use App\Points\CashBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RescueFundTest extends TestCase
{
    use RefreshDatabase;

    private int $deposit = 100_000;

    private int $bet = 200_000;

    private string|int|null $netProfit = 12_345;

    public function test_claim_at_the_maximum_rebate_tier_credits_once_and_starts_a_new_independent_period(): void
    {
        [$member, $binding] = $this->scenario();
        $this->deposit += 50_000;
        $this->bet += 175_000;
        $this->netProfit = -37_655;

        $this->getJson('/api/v1/rescue-fund')->assertOk()
            ->assertJsonPath('settings.max_wager_tier_micros', 3_000_000)
            ->assertJsonPath('data.0.binding_id', $binding->id)
            ->assertJsonPath('data.0.logo_url', 'https://example.test/logo.png')
            ->assertJsonPath('data.0.deposit_cents', 50_000)
            ->assertJsonPath('data.0.net_loss_cents', 50_000)
            ->assertJsonPath('data.0.wager_tier_micros', 3_000_000)
            ->assertJsonPath('data.0.amount_cents', 5_000);

        // Regular cashback settlement can move its own baseline without consuming rescue eligibility.
        $binding->forceFill(['baseline_deposit_cents' => $this->deposit, 'baseline_valid_bet_cents' => $this->bet])->save();
        $claimPath = "/api/v1/rescue-fund/{$binding->id}/claim";
        $attempt = ['request_id' => (string) Str::uuid()];
        $claim = $this->postJson($claimPath, $attempt)->assertSuccessful()
            ->assertJsonPath('data.amount_cents', 5_000)->json('data.id');
        $this->postJson($claimPath, $attempt)->assertSuccessful()->assertJsonPath('data.id', $claim);
        $this->postJson($claimPath, ['request_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('unavailable.0.binding_id', $binding->id)
            ->assertJsonPath('unavailable.0.logo_url', 'https://example.test/logo.png');
        $this->assertSame(5_000, $member->refresh()->available_balance_cents);
        $this->assertDatabaseHas('cash_balance_entries', ['source' => 'rescue_fund', 'source_id' => $claim, 'amount_cents' => 5_000]);
        $this->getJson('/api/v1/cash-wallet/entries?source=rescue_fund')->assertOk()
            ->assertJsonPath('data.0.platform_name', 'Example')->assertJsonPath('data.0.amount_cents', 5_000)
            ->assertJsonPath('data.0.balance_after_cents', 5_000);

        $this->travel(1)->minutes();
        $this->deposit += 1_000;
        $this->bet += 1_000;
        $this->netProfit = -38_655;
        $this->postJson($claimPath, ['request_id' => (string) Str::uuid()])->assertSuccessful()->assertJsonPath('data.amount_cents', 100);
        $this->assertSame(5_100, $member->refresh()->available_balance_cents);
        $this->assertDatabaseCount('rescue_fund_claims', 2);
    }

    public function test_list_shows_owned_bindings_even_when_the_feature_is_disabled(): void
    {
        [, $binding] = $this->scenario();
        $binding->forceFill(['protection_status' => 'inactive'])->save();
        $other = Member::create(['phone' => '21912345678', 'password' => 'member-password']);
        $otherPlatform = Platform::create(['name' => 'Other platform', 'promotion_url' => 'https://other.example.test', 'rating' => 5]);
        PlatformBinding::create([
            'member_id' => $other->id, 'platform_id' => $otherPlatform->id,
            'platform_account_id' => '4000000002', 'query_phone' => $other->phone,
            'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0, 'baseline_observed_at' => now(),
        ]);
        DB::table('rescue_fund_settings')->where('id', 1)->update(['enabled' => false]);

        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonPath('settings.enabled', false)
            ->assertJsonCount(0, 'data')->assertJsonCount(1, 'unavailable')
            ->assertJsonPath('unavailable.0.binding_id', $binding->id)
            ->assertJsonPath('unavailable.0.platform_name', 'Example')
            ->assertJsonMissingPath('unavailable.0.amount_cents');
    }

    public function test_missing_profit_a_higher_tier_and_insufficient_net_loss_are_ineligible(): void
    {
        [, $binding] = $this->scenario();
        $this->deposit += 50_000;
        $this->bet += 200_000;
        $this->netProfit = -50_000;
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('unavailable.0.deposit_cents', 50_000)
            ->assertJsonPath('unavailable.0.net_loss_cents', 62_345)
            ->assertJsonPath('unavailable.0.wager_tier_micros', 4_000_000)
            ->assertJsonPath('unavailable.0.amount_cents', 0);

        $this->bet -= 50_000;
        $this->netProfit = -20_000;
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('unavailable.0.net_loss_cents', 32_345)
            ->assertJsonPath('unavailable.0.wager_tier_micros', 3_000_000)
            ->assertJsonPath('unavailable.0.amount_cents', 0);

        $this->netProfit = null;
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonMissingPath('unavailable.0.net_loss_cents');
        $this->postJson("/api/v1/rescue-fund/{$binding->id}/claim", ['request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseEmpty('cash_balance_entries');
    }

    public function test_legacy_binding_starts_with_the_first_verified_profit_snapshot(): void
    {
        [, $binding] = $this->scenario();
        $this->deposit += 50_000;
        $this->bet += 50_000;
        $this->netProfit = -50_000;
        $binding->forceFill(['protection_status' => 'inactive'])->save();
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonCount(0, 'data');

        $binding->forceFill(['protection_status' => 'active', 'rescue_baseline' => null])->save();
        $this->postJson("/api/v1/rescue-fund/{$binding->id}/claim", ['request_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseEmpty('rescue_fund_claims');
        $this->assertSame(-50_000, $binding->refresh()->rescue_baseline['net_profit_cents']);

        $this->travel(1)->minutes();
        $this->deposit += 1_000;
        $this->bet += 1_000;
        $this->netProfit = -51_000;
        $this->getJson('/api/v1/rescue-fund')->assertOk()->assertJsonPath('data.0.amount_cents', 100);
    }

    public function test_panda_uses_history_wins_loses_without_an_explicit_mapping(): void
    {
        $this->netProfit = 345;
        [, $binding] = $this->scenario(usePandaDefaults: true);
        $this->deposit += 1_000;
        $this->bet += 1_000;
        $this->netProfit = -655;

        $this->getJson('/api/v1/rescue-fund')->assertOk()
            ->assertJsonPath('data.0.binding_id', $binding->id)
            ->assertJsonPath('data.0.net_loss_cents', 1_000)
            ->assertJsonPath('data.0.amount_cents', 100);
    }

    public function test_claim_is_unauthorized_for_another_member(): void
    {
        [, $binding] = $this->scenario();
        $other = Member::create(['phone' => '21912345678', 'password' => 'member-password']);
        $this->actingAs($other->refresh(), 'member')->postJson("/api/v1/rescue-fund/{$binding->id}/claim", [
            'request_id' => (string) Str::uuid(),
        ])->assertNotFound();
    }

    public function test_a_failed_credit_does_not_advance_the_baseline(): void
    {
        [$member, $binding] = $this->scenario();
        $this->deposit += 50_000;
        $this->bet += 50_000;
        $this->netProfit = -50_000;
        $path = "/api/v1/rescue-fund/{$binding->id}/claim";
        $member->forceFill(['available_balance_cents' => CashBalance::MAX_CENTS])->save();
        $baseline = $binding->rescue_baseline;
        $this->actingAs($member, 'member')->postJson($path, ['request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertSame($baseline, $binding->refresh()->rescue_baseline);
        $this->assertDatabaseEmpty('rescue_fund_claims');
        $this->assertDatabaseEmpty('cash_balance_entries');
    }

    public function test_operator_can_save_global_settings_and_provider_mapping_requires_a_complete_explicit_conversion(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '返利运营', 'permissions' => ['points.manage-rules']]));
        $this->actingAs($operator, 'web')->get('/admin/rescue-fund-configuration')->assertOk();

        Livewire::test(RescueFundConfiguration::class)->set('data', [
            'enabled' => true, 'max_wager_tier' => '3', 'percent' => '10.25',
        ])->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('rescue_fund_settings', [
            'id' => 1, 'enabled' => true, 'max_wager_tier_micros' => 3_000_000, 'percent_micros' => 10_250_000,
        ]);

        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $this->fakeTotals();
        $configuration = $this->queryConfiguration();
        $account = app(PlatformQueryClient::class)->lookup($configuration, '11912345678');
        $this->assertSame(12_345, $account->netProfit->cents);

        $configuration['net_profit_unit'] = 'brl';
        $configuration['net_profit_direction'] = 'loss_positive';
        $this->netProfit = '10.25';
        $account = app(PlatformQueryClient::class)->lookup($configuration, '11912345678');
        $this->assertSame(-1_025, $account->netProfit->cents);

        unset($configuration['net_profit_direction']);
        $configuration['test_phone'] = '11912345678';
        $this->expectException(ValidationException::class);
        app(PlatformQueryClient::class)->validateConfiguration($configuration);
    }

    public function test_activation_rejects_a_draft_with_a_missing_profit_field_without_disabling_the_existing_query(): void
    {
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $this->netProfit = null;
        $this->fakeTotals();
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '合作平台操作员', 'permissions' => ['platforms.test-query']]));
        $platform = Platform::create(['name' => 'Example', 'promotion_url' => 'https://example.test', 'rating' => 5]);
        $configuration = $this->queryConfiguration() + ['test_phone' => '11912345678'];
        $platform->forceFill([
            'active_query_revision' => 1, 'active_query' => array_diff_key($configuration, ['net_profit_field' => true]),
            'draft_query' => $configuration, 'query_revision' => 2,
        ])->save();

        $this->assertSame('net_profit_schema', app(PlatformAdministration::class)->testQuery($platform, $operator));
        $this->assertSame(1, $platform->refresh()->active_query_revision);
        $this->assertSame('available', $platform->status());
    }

    private function scenario(bool $usePandaDefaults = false): array
    {
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $this->fakeTotals();
        PointRule::query()->latest('id')->firstOrFail()->forceFill(['rebate_tiers' => [
            ['multiplier_micros' => 1_000_000, 'percent_micros' => 1_000_000],
            ['multiplier_micros' => 3_000_000, 'percent_micros' => 2_000_000],
            ['multiplier_micros' => 4_000_000, 'percent_micros' => 3_000_000],
        ]])->save();
        DB::table('rescue_fund_settings')->where('id', 1)->update([
            'enabled' => true, 'max_wager_tier_micros' => 3_000_000, 'percent_micros' => 10_000_000,
        ]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $member->forceFill(['phone_verified_at' => now()])->save();
        $platform = Platform::create(['name' => 'Example', 'promotion_url' => 'https://example.test', 'rating' => 5, 'guarantee_amount_brl' => '100.00', 'logo_url' => 'https://example.test/logo.png']);
        $configuration = $this->queryConfiguration();
        if ($usePandaDefaults) {
            $configuration = array_diff_key($configuration, array_flip(['net_profit_field', 'net_profit_unit', 'net_profit_direction']));
        }
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => $configuration])->save();
        $binding = app(BindPlatformAccount::class)->bind($member->refresh(), $platform, $member->phone, null, true);
        $binding->forceFill(['protection_status' => 'active'])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->travel(1)->minutes();

        return [$member, $binding];
    }

    private function queryConfiguration(): array
    {
        return [
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'fixture', 'account' => 'fixture', 'fingerprint' => 'fixture',
            'region_id' => 1, 'tenant_id' => 10,
            'net_profit_field' => 'verifiedProfit', 'net_profit_unit' => 'cents', 'net_profit_direction' => 'profit_positive',
        ];
    }

    private function fakeTotals(): void
    {
        Http::fake(fn () => Http::response(['result' => ['data' => ['json' => ['userList' => [
            'total' => 1, 'pageData' => [[
                'id' => 4000000001, 'historicalPay' => $this->deposit, 'historicalValidBetting' => $this->bet,
                'currency' => 'BRL', 'verifiedProfit' => $this->netProfit, 'historyWinsLoses' => $this->netProfit,
            ]],
        ]]]]]));
    }
}
