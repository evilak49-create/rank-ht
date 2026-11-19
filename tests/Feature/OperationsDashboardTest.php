<?php

namespace Tests\Feature;

use App\Dashboard\DashboardStatistics;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\PixWithdrawal;
use App\Models\User;
use App\RedPackets\RedPackets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_activity_shares_the_browser_identity_across_login_and_counts_again_on_the_next_business_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 02:58:00 UTC'));
        $browser = ['visitor_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/visitor-activity', $browser)->assertNoContent();
        $this->postJson('/api/v1/visitor-activity', $browser)->assertNoContent();
        $this->assertDatabaseCount('daily_visitors', 1);

        $member = Member::create(['phone' => '11900000000', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->postJson('/api/v1/visitor-activity', $browser)->assertNoContent();
        $this->assertDatabaseCount('daily_visitors', 1);

        $this->travel(3)->minutes();
        $this->postJson('/api/v1/visitor-activity', $browser)->assertNoContent();
        $this->postJson('/api/v1/visitor-activity', ['visitor_id' => (string) Str::uuid()])->assertNoContent();
        $stats = app(DashboardStatistics::class)->overview(false);
        $this->assertSame(['yesterday' => 1, 'today' => 2, 'online' => 2], $stats['visitors']);
        $this->assertDatabaseCount('visitor_activity', 2);
        $this->assertDatabaseMissing('visitor_activity', ['visitor_hash' => $browser['visitor_id']]);
        $this->travel(5)->minutes();
        $this->assertSame(0, app(DashboardStatistics::class)->overview(false)['visitors']['online']);
        $this->getJson('/api/v1/health')->assertOk();
        $this->assertDatabaseCount('daily_visitors', 3);
    }

    public function test_paid_withdrawals_use_arrival_day_and_pending_links_respect_permissions(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 02:55:00 UTC'));
        $member = Member::create(['phone' => '11900000000', 'password' => 'member-password'])->refresh();
        $round = app(RedPackets::class)->open($member, (string) Str::uuid());
        $this->withdrawal($member, $round->id, 'paid', 125035, now());
        $this->travel(10)->minutes();
        Member::create(['phone' => '11900000001', 'password' => 'member-password']);
        $paid = $this->withdrawal($member, $round->id, 'paid', 7045, now());
        $paid->forceFill(['created_at' => now()->subDay()])->save();
        $waiting = $this->withdrawal($member, $round->id, 'pending', 10000);
        $this->withdrawal($member, $round->id, 'failed', 5000);

        $stats = app(DashboardStatistics::class)->overview(true);
        $this->assertSame(['total' => 2, 'yesterday' => 1, 'today' => 1], $stats['members']);
        $this->assertSame(['amount' => 132080, 'count' => 2], $stats['withdrawals']['total']);
        $this->assertSame(['amount' => 125035, 'count' => 1], $stats['withdrawals']['yesterday']);
        $this->assertSame(['amount' => 7045, 'count' => 1], $stats['withdrawals']['today']);

        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $this->actingAs($operator, 'web')->get('/admin')->assertOk()->assertDontSee('待审核提款')->assertDontSee('R$ 1.320,80');
        $operator->roles()->attach(AdminRole::create(['name' => '待办处理', 'permissions' => ['pix.view', 'pix.review']]));
        $list = '/admin/pix-withdrawals?'.http_build_query(['filters' => ['status' => ['value' => 'pending']]]);
        $this->get('/admin')->assertOk()->assertSee('待审核提款')->assertSee('R$ 1.320,80')->assertSee($list);
        $this->get($list)->assertOk()->assertSee($waiting->order_number)->assertDontSee($paid->order_number);
    }

    private function withdrawal(Member $member, int $round, string $status, int $amount, ?Carbon $paidAt = null): PixWithdrawal
    {
        $order = PixWithdrawal::create([
            'member_id' => $member->id, 'red_packet_round_id' => $round, 'order_number' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(), 'cpf_snapshot' => '52998224725', 'amount_cents' => $amount, 'status' => $status,
        ]);
        $order->forceFill(['paid_at' => $paidAt])->save();

        return $order;
    }
}
