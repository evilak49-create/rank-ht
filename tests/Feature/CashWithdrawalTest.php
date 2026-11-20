<?php

namespace Tests\Feature;

use App\Filament\Resources\PixWithdrawalResource\Pages\ListPixWithdrawals;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\PixPayoutChannel;
use App\Models\PixWithdrawal;
use App\Models\User;
use App\RedPackets\PixDelivery;
use App\RedPackets\PixResult;
use App\RedPackets\PixWithdrawals;
use App\RedPackets\RedPackets;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CashWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_balance_can_be_partly_withdrawn_manually_or_refunded(): void
    {
        config(['services.pix.driver' => 'disabled']);
        [$member, $operator] = $this->scenario();
        $url = '/api/v1/cash-wallet/withdrawals';
        $this->postJson($url, ['request_id' => (string) Str::uuid(), 'amount_cents' => 0])->assertUnprocessable();
        $this->postJson($url, ['request_id' => (string) Str::uuid(), 'amount_cents' => 1001])->assertConflict();
        $request = ['request_id' => (string) Str::uuid(), 'amount_cents' => 400];
        $id = $this->postJson($url, $request)->assertCreated()->assertJsonPath('data.amount_cents', 400)->json('data.id');
        $this->postJson($url, $request)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($url, [...$request, 'amount_cents' => 300])->assertConflict();
        $this->getJson('/api/v1/cash-wallet')->assertOk()->assertJsonPath('data.available_balance_cents', 600)
            ->assertJsonPath('data.held_balance_cents', 400);
        $this->getJson('/api/v1/pix-withdrawals')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1);

        $orders = app(PixWithdrawals::class);
        $order = PixWithdrawal::findOrFail($id);
        $orders->reject($order, $operator, '审核未通过');
        $orders->reject($order, $operator, '审核未通过');
        $this->assertSame(1000, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);

        $id = $this->postJson($url, ['request_id' => (string) Str::uuid(), 'amount_cents' => 250])->assertCreated()->json('data.id');
        $order = PixWithdrawal::findOrFail($id);
        $orders->approve($order, $operator);
        $this->assertDatabaseEmpty('pix_tasks');
        $this->actingAs($operator, 'web');
        $this->get('/admin/pix-withdrawals')->assertOk()->assertSee('待人工出款');
        Livewire::test(ListPixWithdrawals::class)->callAction(TestAction::make('manual_paid')->table($order), [
            'reference' => 'TEST-PIX-001',
        ])->assertHasNoActionErrors();
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(750, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 3);
    }

    public function test_failed_automated_balance_transfer_releases_the_hold_once(): void
    {
        config(['services.pix.driver' => 'simulator']);
        [$member, $operator] = $this->scenario();
        $id = $this->postJson('/api/v1/cash-wallet/withdrawals', [
            'request_id' => (string) Str::uuid(), 'amount_cents' => 250,
        ])->assertCreated()->json('data.id');
        $order = PixWithdrawal::findOrFail($id);
        app(PixWithdrawals::class)->approve($order, $operator);
        $order->task()->update(['send_started_at' => now()]);
        $delivery = app(PixDelivery::class);
        $delivery->reconcile($order, new PixResult('failed'));
        $delivery->reconcile($order, new PixResult('failed'));
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(1000, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 2);
    }

    public function test_staff_can_configure_channels_and_choose_one_for_a_pending_withdrawal(): void
    {
        config(['services.pix.driver' => 'disabled']);
        [$member, $operator] = $this->scenario();
        $id = $this->postJson('/api/v1/cash-wallet/withdrawals', [
            'request_id' => (string) Str::uuid(), 'amount_cents' => 250,
        ])->assertCreated()->json('data.id');
        $order = PixWithdrawal::findOrFail($id);
        $this->actingAs($operator, 'web');
        Livewire::test(ListPixWithdrawals::class)->assertActionHidden('add_channel');
        $operator->roles()->firstOrFail()->update(['permissions' => ['pix.view', 'pix.review', 'pix.manage-channels']]);
        Livewire::test(ListPixWithdrawals::class)->callAction('add_channel', ['name' => '渠道甲'])->assertHasNoActionErrors()
            ->callAction('add_channel', ['name' => '渠道乙'])->assertHasNoActionErrors();
        $first = PixPayoutChannel::where('name', '渠道甲')->sole();
        $second = PixPayoutChannel::where('name', '渠道乙')->sole();
        Livewire::test(ListPixWithdrawals::class)->callAction('channel_availability', ['channel' => $second->id, 'enabled' => false])
            ->assertHasNoActionErrors();

        $approve = TestAction::make('approve')->table($order);
        Livewire::test(ListPixWithdrawals::class)->callAction($approve, [])->assertHasActionErrors(['channel' => 'required']);
        Livewire::test(ListPixWithdrawals::class)->callAction($approve, ['channel' => 'provider:'.$second->id])->assertHasActionErrors(['channel']);
        $this->assertSame('pending', $order->refresh()->status);
        Livewire::test(ListPixWithdrawals::class)->callAction($approve, ['channel' => 'provider:'.$first->id])->assertHasNoActionErrors();
        $this->assertSame('processing', $order->refresh()->status);
        $this->assertSame($first->id, $order->payout_channel_id);
        $this->assertSame('渠道甲', $order->payout_channel_name);
        $this->assertDatabaseEmpty('pix_tasks');

        app(PixWithdrawals::class)->approve($order, $operator, 'manual');
        $this->assertSame($first->id, $order->refresh()->payout_channel_id);
        Livewire::test(ListPixWithdrawals::class)->callAction(TestAction::make('manual_paid')->table($order), [
            'reference' => 'CHANNEL-TRANSFER-1',
        ])->assertHasNoActionErrors();
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(0, $member->refresh()->held_balance_cents);
    }

    public function test_member_withdrawal_history_combines_both_sources_and_summarizes_statuses(): void
    {
        $url = '/api/v1/withdrawal-history';
        $this->getJson($url)->assertUnauthorized();
        [$member] = $this->scenario();
        $other = Member::create(['phone' => '11900000555', 'password' => 'member-password']);
        $round = app(RedPackets::class)->open($member->refresh(), (string) Str::uuid());
        $entries = [
            ['pending', 'reviewing', null],
            ['processing', 'reviewing', $round->id],
            ['unresolved', 'reviewing', null],
            ['paid', 'succeeded', $round->id],
            ['failed', 'failed', null],
            ['rejected', 'failed', $round->id],
        ];
        $expected = [];
        foreach ($entries as [$status, $summary, $roundId]) {
            $order = PixWithdrawal::create([
                'order_number' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
                'member_id' => $member->id, 'red_packet_round_id' => $roundId,
                'amount_cents' => 250, 'cpf_snapshot' => '52998224725', 'status' => $status,
            ]);
            $expected[] = [$order->id, $roundId === null ? 'balance' : 'red_packet', $summary];
        }
        $unrelated = PixWithdrawal::create([
            'order_number' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'member_id' => $other->id, 'red_packet_round_id' => null,
            'amount_cents' => 1000, 'cpf_snapshot' => '52998224725', 'status' => 'paid',
        ]);

        $history = $this->getJson($url)->assertOk()->assertJsonPath('total', 6)
            ->assertJsonPath('data.0.amount_cents', 250)->assertJsonMissingPath('data.0.masked_cpf')
            ->assertDontSee('52998224725')->assertDontSee($unrelated->order_number);
        $actual = array_map(fn (array $order): array => [$order['id'], $order['source'], $order['status']], $history->json('data'));
        $this->assertSame(array_reverse($expected), $actual);
    }

    private function scenario(): array
    {
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $member->forceFill(['available_balance_cents' => 1000, 'pix_cpf' => '52998224725'])->save();
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '提现审核', 'permissions' => ['pix.view', 'pix.review']]));
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        return [$member, $operator];
    }
}
