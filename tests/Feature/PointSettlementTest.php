<?php

namespace Tests\Feature;

use App\Filament\Resources\PointRuleResource\Pages\ListPointRules;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointRule;
use App\Models\User;
use App\Points\CashBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PointSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_a_quote_credits_once_and_carries_the_fraction_forward(): void
    {
        [$member, $binding] = $this->scenario();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $quote = $this->postJson($base)->assertCreated()->assertJsonPath('data.estimated_points', 1)
            ->assertJsonPath('data.remainder_after', '0.50000000')->json('data.id');
        $second = $this->postJson($base)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/point-quotes/$quote/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/point-quotes/$quote/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/point-quotes/$second/confirm")->assertConflict();
        $this->assertSame(1, $member->refresh()->points_balance);
        $this->assertDatabaseCount('point_entries', 1);
        $this->assertDatabaseHas('platform_bindings', ['id' => $binding->id, 'baseline_deposit_cents' => 16000]);
    }

    public function test_rule_changes_leave_an_old_quote_unchanged_and_expired_quotes_cannot_be_confirmed(): void
    {
        [$member, $binding] = $this->scenario();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $old = $this->postJson($base)->assertCreated()->assertJsonPath('data.estimated_points', 1)->json('data.id');

        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '积分运营', 'permissions' => ['points.manage-rules', 'points.view']]));
        $this->actingAs($operator, 'web')->get('/admin/point-rules')->assertOk();
        Livewire::test(ListPointRules::class)->callAction('update_rule', [
            'points_per_brl' => '0.020000', 'wager_multiplier' => '1.000000',
        ])->assertHasNoActionErrors();
        $this->get('/admin/point-quotes')->assertOk();

        $this->actingAs($member, 'member');
        $new = $this->postJson($base)->assertCreated()->assertJsonPath('data.estimated_points', 3)->json('data.id');
        $this->postJson("/api/v1/point-quotes/$old/confirm")->assertOk()->assertJsonPath('data.estimated_points', 1);
        $this->travel(10)->minutes();
        $this->postJson("/api/v1/point-quotes/$new/confirm")->assertConflict()->assertJsonPath('message', '报价已过期，请重新查询。');
        $this->assertSame(1, $member->refresh()->points_balance);
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

    public function test_admin_can_configure_rebate_percentage_and_wager_without_changing_point_rules(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '返利运营', 'permissions' => ['points.manage-rules']]));
        $this->actingAs($operator, 'web');
        $previous = PointRule::query()->latest('id')->firstOrFail();

        Livewire::test(ListPointRules::class)->callAction('update_rebate_rule', [
            'rebate_percent' => '101', 'rebate_wager_multiplier' => '2.5',
        ])->assertHasActionErrors(['rebate_percent']);
        $this->assertSame($previous->id, PointRule::query()->latest('id')->value('id'));
        Livewire::test(ListPointRules::class)->callAction('update_rebate_rule', [
            'rebate_percent' => '3.125', 'rebate_wager_multiplier' => '2.5',
        ])->assertHasNoActionErrors();
        $current = PointRule::query()->latest('id')->firstOrFail();
        $this->assertSame($previous->points_per_brl_micros, $current->points_per_brl_micros);
        $this->assertSame($previous->wager_multiplier_micros, $current->wager_multiplier_micros);
        $this->assertSame(3125000, $current->rebate_percent_micros);
        $this->assertSame(2500000, $current->rebate_wager_multiplier_micros);

        Livewire::test(ListPointRules::class)->callAction('update_rule', [
            'points_per_brl' => '0.02', 'wager_multiplier' => '4',
        ])->assertHasNoActionErrors();
        $updated = PointRule::query()->latest('id')->firstOrFail();
        $this->assertSame(3125000, $updated->rebate_percent_micros);
        $this->assertSame(2500000, $updated->rebate_wager_multiplier_micros);
    }

    public function test_one_confirmation_credits_points_and_cash_after_both_wager_requirements_are_met(): void
    {
        [$member, $binding] = $this->scenario();
        PointRule::query()->firstOrFail()->forceFill(['rebate_percent_micros' => 3125000, 'rebate_wager_multiplier_micros' => 2000000])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $base = "/api/v1/platform-bindings/{$binding->id}/point-quotes";
        $blocked = $this->postJson($base)->assertCreated()->assertJsonPath('data.eligibility', 'insufficient_wager')
            ->assertJsonPath('data.point_required_bet_cents', 15000)->assertJsonPath('data.rebate_required_bet_cents', 30000)
            ->assertJsonPath('data.estimated_points', 1)->assertJsonPath('data.rebate_cents', 468)->json('data.id');
        $this->postJson("/api/v1/point-quotes/$blocked/confirm")->assertConflict();
        $this->assertSame(0, $member->refresh()->available_balance_cents);

        $this->fakeTotals(16000, 32000);
        $id = $this->postJson($base)->assertCreated()->assertJsonPath('data.eligibility', 'eligible')->json('data.id');
        $competing = $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/point-quotes/$id/confirm")->assertOk()->assertJsonPath('data.rebate_cents', 468);
        $this->postJson("/api/v1/point-quotes/$id/confirm")->assertOk();
        $this->postJson("/api/v1/point-quotes/$competing/confirm")->assertConflict();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('available_balance_cents', 468);
        $this->assertSame(1, $member->refresh()->points_balance);
        $this->assertSame(75000000, $binding->refresh()->rebate_remainder);
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertDatabaseHas('cash_balance_entries', ['source' => 'rebate', 'source_id' => $id, 'amount_cents' => 468]);

        $this->fakeTotals(16008, 32016);
        $next = $this->postJson($base)->assertCreated()->assertJsonPath('data.eligibility', 'eligible')
            ->assertJsonPath('data.estimated_points', 0)->assertJsonPath('data.rebate_cents', 1)->json('data.id');
        $this->postJson("/api/v1/point-quotes/$next/confirm")->assertOk()->assertJsonPath('data.point_entry_id', null);
        $this->assertSame(469, $member->refresh()->available_balance_cents);
        $this->assertSame(1, DB::table('point_entries')->count());
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

        $quote = $this->postJson("$path/point-quotes", ['cash_only' => true])->assertCreated()
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
