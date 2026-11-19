<?php

namespace Tests\Feature;

use App\Filament\Resources\PointRuleResource\Pages\ListPointRules;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        Http::fake(['*' => Http::response([
            'result' => ['data' => ['json' => ['userList' => [
                'total' => 1, 'pageData' => [['id' => 4000000001, 'historicalPay' => $deposit,
                    'historicalValidBetting' => 17000, 'currency' => 'BRL']],
            ]]]],
        ])]);

        return [$member, $binding];
    }
}
