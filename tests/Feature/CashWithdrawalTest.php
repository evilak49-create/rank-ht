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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CashWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_movement_history_records_withdrawal_and_its_return_separately(): void
    {
        $url = '/api/v1/cash-wallet/entries';
        $this->getJson($url)->assertUnauthorized();
        [$member, $operator] = $this->scenario();
        $other = Member::create(['phone' => '11900000555', 'password' => 'member-password']);
        $createdAt = now()->subMinute();
        DB::table('cash_balance_entries')->insert([
            ['member_id' => $member->id, 'source' => 'rebate', 'source_id' => 5, 'amount_cents' => 100,
                'balance_after_cents' => 1000, 'created_at' => $createdAt],
            ['member_id' => $other->id, 'source' => 'rebate', 'source_id' => 6, 'amount_cents' => 900,
                'balance_after_cents' => 900, 'created_at' => $createdAt],
        ]);

        $orderId = $this->postJson('/api/v1/cash-wallet/withdrawals', [
            'request_id' => (string) Str::uuid(), 'amount_cents' => 400,
        ])->assertCreated()->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.source', 'withdrawal')->assertJsonPath('data.0.amount_cents', -400)
            ->assertJsonPath('data.0.balance_after_cents', 600)->assertJsonMissingPath('data.0.status');

        $order = PixWithdrawal::findOrFail($orderId);
        app(PixWithdrawals::class)->cancel($order, $operator);
        $history = $this->getJson($url)->assertOk()->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.source', 'withdrawal_refund')->assertJsonPath('data.0.amount_cents', 400)
            ->assertJsonPath('data.0.balance_after_cents', 1000)->assertJsonPath('data.1.source', 'withdrawal')
            ->assertJsonPath('data.2.source', 'rebate')->assertJsonPath('data.2.amount_cents', 100)
            ->assertJsonMissingPath('data.0.status');
        $this->assertTrue($history->headers->hasCacheControlDirective('no-store'));
        $this->getJson($url.'?source=withdrawal_refund')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.amount_cents', 400);
        $this->getJson($url.'?source[]=rebate')->assertUnprocessable();
    }

    public function test_available_balance_can_be_partly_withdrawn_manually_or_refunded(): void
    {
        config(['services.pix.driver' => 'disabled']);
        [$member, $operator] = $this->scenario();
        $url = '/api/v1/cash-wallet/withdrawals';
        $cash = $this->getJson('/api/v1/cash-wallet')->assertOk()->assertJsonPath('data.cpf', $member->pix_cpf);
        $this->assertTrue($cash->headers->hasCacheControlDirective('no-store'));
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
        $this->assertSame(600, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);
        $this->assertTrue($order->refresh()->rejection_deducted);
        $this->assertDatabaseMissing('cash_balance_entries', ['source' => 'withdrawal_refund', 'source_id' => $id]);

        $cancelled = $this->postJson($url, ['request_id' => (string) Str::uuid(), 'amount_cents' => 150])
            ->assertCreated()->json('data.id');
        $cancelledOrder = PixWithdrawal::findOrFail($cancelled);
        $orders->cancel($cancelledOrder, $operator);
        $orders->cancel($cancelledOrder, $operator);
        $this->assertSame('cancelled', $cancelledOrder->refresh()->status);
        $this->assertSame(600, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);
        $this->assertDatabaseHas('cash_balance_entries', ['source' => 'withdrawal_refund', 'source_id' => $cancelled, 'amount_cents' => 150]);

        $this->travel(1)->minutes();
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
        $this->assertSame(350, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 4);
    }

    public function test_failed_automated_balance_transfer_releases_the_hold_once(): void
    {
        config(['services.pix.driver' => 'simulator']);
        [$member, $operator] = $this->scenario();
        $id = $this->postJson('/api/v1/cash-wallet/withdrawals', [
            'request_id' => (string) Str::uuid(), 'amount_cents' => 250,
        ])->assertCreated()->json('data.id');
        $order = PixWithdrawal::findOrFail($id);
        $orders = app(PixWithdrawals::class);
        $orders->approve($order, $operator);
        $order->task()->update(['send_started_at' => now()]);
        $this->assertThrows(fn () => $orders->cancel($order, $operator), HttpException::class, '该订单已结单或正在渠道出款。');
        $this->assertThrows(fn () => $orders->reject($order, $operator, '审核未通过'), HttpException::class, '该订单已结单或正在渠道出款。');
        $this->assertSame(250, $member->refresh()->held_balance_cents);
        $delivery = app(PixDelivery::class);
        $delivery->reconcile($order, new PixResult('failed'));
        $delivery->reconcile($order, new PixResult('failed'));
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(1000, $member->refresh()->available_balance_cents);
        $this->assertSame(0, $member->held_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 2);
    }

    public function test_staff_can_only_approve_new_payouts_through_an_automated_channel_and_still_close_legacy_transfers(): void
    {
        config(['services.pix.driver' => 'disabled']);
        [$member, $operator] = $this->scenario();
        $id = $this->postJson('/api/v1/cash-wallet/withdrawals', [
            'request_id' => (string) Str::uuid(), 'amount_cents' => 250,
        ])->assertCreated()->json('data.id');
        $order = PixWithdrawal::findOrFail($id);
        $oldChannel = PixPayoutChannel::create(['name' => '历史人工渠道', 'enabled' => true]);
        $this->actingAs($operator, 'web');

        $approve = TestAction::make('approve')->table($order);
        Livewire::test(ListPixWithdrawals::class)->callAction($approve, [])->assertHasActionErrors(['channel' => 'required']);
        Livewire::test(ListPixWithdrawals::class)->callAction($approve, ['channel' => 'provider:'.$oldChannel->id])
            ->assertHasActionErrors(['channel']);
        $this->assertSame('pending', $order->refresh()->status);

        app(PixWithdrawals::class)->approve($order, $operator, 'provider:'.$oldChannel->id);
        $this->assertSame('processing', $order->refresh()->status);
        $this->assertSame($oldChannel->id, $order->payout_channel_id);
        $this->assertDatabaseEmpty('pix_tasks');

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
            ['cancelled', 'cancelled', null],
        ];
        $expected = [];
        foreach ($entries as [$status, $summary, $roundId]) {
            $order = PixWithdrawal::create([
                'order_number' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
                'member_id' => $member->id, 'red_packet_round_id' => $roundId,
                'amount_cents' => 250, 'cpf_snapshot' => '52998224725', 'status' => $status,
            ]);
            $expected[] = [$order->id, $roundId === null ? 'balance' : 'red_packet', $summary, $status];
        }
        $unrelated = PixWithdrawal::create([
            'order_number' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'member_id' => $other->id, 'red_packet_round_id' => null,
            'amount_cents' => 1000, 'cpf_snapshot' => '52998224725', 'status' => 'paid',
        ]);

        $history = $this->getJson($url)->assertOk()->assertJsonPath('total', 7)
            ->assertJsonPath('data.0.amount_cents', 250)->assertJsonMissingPath('data.0.masked_cpf')
            ->assertDontSee('52998224725')->assertDontSee($unrelated->order_number);
        $actual = array_map(fn (array $order): array => [$order['id'], $order['source'], $order['status'], $order['lifecycle_status']], $history->json('data'));
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
