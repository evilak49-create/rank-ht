<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\FundRateVersion;
use App\Jobs\DispatchDailyCashback;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointRule;
use App\Models\User;
use App\Points\CashBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PointSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashback.manual' => true]);
    }

    public function test_confirmation_requeries_for_highest_tier_and_advances_the_period_only_once(): void
    {
        [$member, $binding] = $this->scenario();
        PointRule::query()->firstOrFail()->forceFill(['rebate_tiers' => [
            ['multiplier_micros' => 2000000, 'percent_micros' => 1000000],
            ['multiplier_micros' => 3000000, 'percent_micros' => 2000000],
            ['multiplier_micros' => 4000000, 'percent_micros' => 3000000],
        ]])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $blocked = $this->postJson($base)->assertCreated()->assertJsonPath('data.eligibility', 'insufficient_wager')
            ->assertJsonPath('data.rebate_cents', 0)->json('data.id');
        $this->postJson("/api/v1/point-quotes/$blocked/confirm")->assertConflict()
            ->assertJsonPath('message', '当前打码未达到返利标准');
        $this->assertSame(1000, $binding->refresh()->baseline_deposit_cents);
        $this->assertDatabaseEmpty('cash_balance_entries');

        $this->fakeTotals(16000, 54500);
        $quote = $this->postJson($base)->assertCreated()->assertJsonPath('data.estimated_points', 0)
            ->assertJsonPath('data.matched_rebate_wager_multiplier', '3.000000')
            ->assertJsonPath('data.rebate_cents', 300)->json('data.id');
        $second = $this->postJson($base)->assertCreated()->json('data.id');

        $this->fakeTotals(16000, 62000);
        $this->postJson("/api/v1/point-quotes/$quote/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.matched_rebate_wager_multiplier', '4.000000')
            ->assertJsonPath('data.rebate_cents', 450);
        $this->postJson("/api/v1/point-quotes/$quote/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/point-quotes/$second/confirm")->assertConflict();
        Http::assertSentCount(1);
        $this->assertSame(0, $member->refresh()->points_balance);
        $this->assertDatabaseEmpty('point_entries');
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertDatabaseHas('platform_bindings', ['id' => $binding->id, 'baseline_deposit_cents' => 16000, 'baseline_valid_bet_cents' => 62000]);
        $this->fakeTotals(17000, 64000);
        $this->postJson($base)->assertCreated()->assertJsonPath('data.deposit_cents', 1000)
            ->assertJsonPath('data.valid_bet_cents', 2000)->assertJsonPath('data.rebate_cents', 10);
    }

    public function test_an_existing_quote_uses_the_current_rebate_rule_at_confirmation_and_still_expires(): void
    {
        [$member, $binding] = $this->scenario();
        PointRule::query()->firstOrFail()->forceFill(['rebate_percent_micros' => 1000000])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $old = $this->postJson($base)->assertCreated()->assertJsonPath('data.rebate_cents', 150)->json('data.id');
        $expiring = $this->postJson($base)->assertCreated()->json('data.id');

        PointRule::query()->firstOrFail()->forceFill(['rebate_percent_micros' => 2000000])->save();
        $this->getJson("/api/v1/point-quotes/$old")->assertOk()->assertJsonPath('data.rebate_cents', 150);
        $this->postJson("/api/v1/point-quotes/$old/confirm")->assertOk()->assertJsonPath('data.rebate_cents', 300);
        $this->travel(10)->minutes();
        $this->postJson("/api/v1/point-quotes/$expiring/confirm")->assertConflict()
            ->assertJsonPath('message', '报价已过期，请重新查询。');
        $this->assertSame(300, $member->refresh()->available_balance_cents);
    }

    public function test_a_regression_pauses_settlement_without_replacing_the_existing_baseline(): void
    {
        [$member, $binding] = $this->scenario(deposit: 900);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $id = $this->postJson($base)->assertCreated()->assertJsonPath('data.eligibility', 'regressed')->json('data.id');
        $this->postJson($base)->assertCreated()->assertJsonPath('data.eligibility', 'paused');
        $this->postJson("/api/v1/point-quotes/$id/confirm")->assertConflict();
        $this->assertSame(1000, $binding->refresh()->baseline_deposit_cents);
        $this->assertDatabaseCount('point_entries', 0);
    }

    public function test_legacy_platform_funding_rate_is_not_in_cashback_configuration(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '返利运营', 'permissions' => ['points.manage-rules']]));
        $this->actingAs($operator, 'web');
        $rule = PointRule::query()->latest('id')->firstOrFail();
        $this->get('/admin/rebate-configuration')->assertOk()->assertDontSee('设置平台资金比例');
        app(\App\Shop\Funds\FundRates::class)->save('1.75', $operator);

        $this->assertSame(175, FundRateVersion::query()->latest('id')->firstOrFail()->cents_per_point);
        $this->assertSame($rule->id, PointRule::query()->latest('id')->value('id'));
    }

    public function test_an_additional_deposit_can_make_a_previously_eligible_cashback_quote_ineligible(): void
    {
        [$member, $binding] = $this->scenario();
        PointRule::query()->firstOrFail()->forceFill([
            'rebate_percent_micros' => 3125000, 'rebate_wager_multiplier_micros' => 2000000,
        ])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $this->fakeTotals(16000, 32000);
        $id = $this->postJson($base)->assertCreated()->assertJsonPath('data.rebate_cents', 468)->json('data.id');

        $this->fakeTotals(17000, 32000);
        $this->postJson("/api/v1/point-quotes/$id/confirm")->assertConflict()
            ->assertJsonPath('message', '当前打码未达到返利标准');
        $this->getJson("/api/v1/point-quotes/$id")->assertOk()
            ->assertJsonPath('data.eligibility', 'insufficient_wager')->assertJsonPath('data.confirmed_at', null);
        $this->assertSame(1000, $binding->refresh()->baseline_deposit_cents);
        $this->assertDatabaseEmpty('cash_balance_entries');

        $this->fakeTotals(17000, 34000);
        $this->postJson("/api/v1/point-quotes/$id/confirm")->assertOk()
            ->assertJsonPath('data.rebate_cents', 500)->assertJsonPath('data.point_entry_id', null);
        $this->assertSame(500, $member->refresh()->available_balance_cents);
        $this->assertDatabaseHas('cash_balance_entries', ['source' => 'rebate', 'source_id' => $id, 'amount_cents' => 500]);
    }

    public function test_a_failed_cash_credit_does_not_post_points_or_consume_the_deposit(): void
    {
        [$member, $binding] = $this->scenario();
        $member->forceFill(['available_balance_cents' => CashBalance::MAX_CENTS])->save();
        PointRule::query()->firstOrFail()->forceFill(['rebate_percent_micros' => 3000000])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $id = $this->postJson("/api/v1/platform-bindings/{$binding->id}/point-quotes")
            ->assertCreated()->assertJsonPath('data.eligibility', 'eligible')->json('data.id');

        $this->postJson("/api/v1/point-quotes/$id/confirm")->assertConflict();
        $this->assertSame(0, $member->refresh()->points_balance);
        $this->assertSame(1000, $binding->refresh()->baseline_deposit_cents);
        $this->assertDatabaseEmpty('point_entries');
        $this->assertDatabaseEmpty('cash_balance_entries');
    }

    public function test_bound_totals_and_cash_only_settlement_do_not_award_points(): void
    {
        [$member, $binding] = $this->scenario();
        $binding->forceFill(['point_remainder' => 25000000])->save();
        PointRule::query()->firstOrFail()->forceFill([
            'wager_multiplier_micros' => 4000000,
            'rebate_wager_multiplier_micros' => 1000000,
            'rebate_percent_micros' => 3000000,
        ])->save();

        $path = "/api/v1/platform-bindings/{$binding->id}";
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson("$path/totals")->assertOk()
            ->assertJsonPath('data.total_deposit_cents', '16000')
            ->assertJsonPath('data.total_valid_bet_cents', '17000');
        $this->assertDatabaseEmpty('point_quotes');

        $quote = $this->postJson("$path/point-quotes", ['cash_only' => false])->assertCreated()
            ->assertJsonPath('data.cash_only', true)
            ->assertJsonPath('data.eligibility', 'eligible')
            ->assertJsonPath('data.estimated_points', 0)
            ->assertJsonPath('data.rebate_cents', 450)->json('data.id');
        $this->postJson("/api/v1/point-quotes/$quote/confirm")->assertOk();
        $this->postJson("/api/v1/point-quotes/$quote/confirm")->assertOk();
        $this->assertSame(0, $member->refresh()->points_balance);
        $this->assertSame(450, $member->available_balance_cents);
        $this->assertSame(25000000, $binding->refresh()->point_remainder);
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertDatabaseEmpty('point_entries');
    }

    public function test_bound_totals_are_only_available_to_the_binding_owner(): void
    {
        [, $binding] = $this->scenario();
        $stranger = Member::create(['phone' => '21912345678', 'password' => 'member-password']);
        $this->actingAs($stranger, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson("/api/v1/platform-bindings/{$binding->id}/totals")->assertNotFound();
    }

    public function test_daily_cashback_preserves_zero_periods_and_credits_at_most_once_per_brazilian_day(): void
    {
        config(['cashback.manual' => false]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-24 11:59:00', 'America/Sao_Paulo'));
        [$member, $binding] = $this->scenario(11000);
        PointRule::query()->firstOrFail()->forceFill(['rebate_tiers' => [
            ['multiplier_micros' => 2000000, 'percent_micros' => 2000000],
        ]])->save();

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson('/api/v1/cashback')->assertOk()
            ->assertJsonPath('data.0.binding_id', $binding->id)
            ->assertJsonPath('next_dispatch_at', '2026-11-24T15:00:00.000000Z');
        $this->getJson("/api/v1/cashback/{$binding->id}")->assertOk()
            ->assertJsonPath('data.current_cents', 0)->assertJsonPath('data.estimated_cents', 200);
        $this->postJson("/api/v1/platform-bindings/{$binding->id}/point-quotes")->assertGone();

        Queue::fake();
        $this->artisan('cashback:dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
        $settlement = app(\App\Points\PointSettlement::class);
        $settlement->dispatchDaily($binding->id, '2026-11-24');
        $this->getJson('/api/v1/cashback/history')->assertOk()->assertJsonCount(0, 'data');

        $this->travel(1)->minutes();
        $this->artisan('cashback:dispatch')->assertSuccessful();
        Queue::assertPushed(DispatchDailyCashback::class, fn ($job) => $job->bindingId === $binding->id && $job->day === '2026-11-24');
        $settlement->dispatchDaily($binding->id, '2026-11-24');
        $this->assertSame(1, $binding->refresh()->baseline_version);
        $this->assertSame(0, $member->refresh()->available_balance_cents);
        $this->getJson('/api/v1/cashback/history')->assertOk()
            ->assertJsonPath('data.0.result', 'insufficient_wager')
            ->assertJsonPath('data.0.amount_cents', 0);

        $this->travel(1)->days();
        $this->fakeTotals(11000, 25000);
        $settlement->dispatchDaily($binding->id, '2026-11-25');
        $settlement->dispatchDaily($binding->id, '2026-11-25');
        $this->assertSame(2, $binding->refresh()->baseline_version);
        $this->assertSame(200, $member->refresh()->available_balance_cents);
        $this->getJson('/api/v1/cashback/history')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.result', 'credited')
            ->assertJsonPath('data.0.amount_cents', 200);

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $other = Member::create(['phone' => '21912345678', 'password' => 'member-password']);
        $this->actingAs($other, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson("/api/v1/cashback/{$binding->id}")->assertNotFound();
        $this->getJson('/api/v1/cashback/history')->assertOk()->assertJsonCount(0, 'data');
    }

    private function scenario(int $deposit = 16000): array
    {
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $platform = Platform::create(['name' => 'Example', 'promotion_url' => 'https://example.test', 'rating' => 5]);
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => [
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'fixture', 'account' => 'fixture',
            'fingerprint' => 'fixture', 'region_id' => 1, 'tenant_id' => 10,
        ]])->save();
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id,
            'platform_account_id' => '4000000001', 'query_phone' => $member->phone,
            'baseline_deposit_cents' => 1000, 'baseline_valid_bet_cents' => 2000,
            'baseline_observed_at' => now()->subDay(),
        ]);
        $this->fakeTotals($deposit, 17000);

        return [$member, $binding];
    }

    private function fakeTotals(int $deposit, int $bet): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([
            'result' => ['data' => ['json' => ['userList' => [
                'total' => 1, 'pageData' => [['id' => 4000000001, 'historicalPay' => $deposit,
                    'historicalValidBetting' => $bet, 'currency' => 'BRL']],
            ]]]],
        ])]);

    }
}
