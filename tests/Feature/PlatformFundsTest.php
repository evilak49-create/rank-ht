<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformFundOrderResource\Pages\ListPlatformFundOrders;
use App\Jobs\DeliverPlatformFunds;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformFundOrder;
use App\Models\User;
use App\Shop\Funds\FundChannel;
use App\Shop\Funds\FundDelivery;
use App\Shop\Funds\FundRates;
use App\Shop\Funds\FundResult;
use App\Shop\Funds\PlatformFunds;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformFundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_changes_require_confirmation_and_checkout_retries_return_the_original_order(): void
    {
        [$member, $binding, $operator] = $this->fixture();
        $payload = ['binding_id' => $binding->id, 'points' => 3];
        $service = app(PlatformFunds::class);
        $this->postJson('/api/v1/platform-funds/preview', $payload)->assertUnprocessable();
        $preview = $service->preview($member, $payload);
        $this->assertSame(300, $preview['amount_cents']);
        $version = $preview['rate_version'];
        app(FundRates::class)->save('1.25', $operator);
        $payload += ['request_id' => (string) Str::uuid(), 'rate_version' => $version];
        $this->postJson('/api/v1/platform-funds/orders', $payload)->assertUnprocessable();
        try {
            $service->checkout($member, $payload);
            $this->fail('A stale rate must be rejected.');
        } catch (HttpResponseException $error) {
            $this->assertSame(409, $error->getResponse()->getStatusCode());
            $response = $error->getResponse()->getData(true);
            $this->assertSame('rate_changed', $response['code']);
            $this->assertSame(375, $response['data']['amount_cents']);
            $latest = $response['data']['rate_version'];
        }
        $this->assertSame(20, $member->refresh()->points_balance);

        $payload['rate_version'] = $latest;
        $orderId = $service->checkout($member, $payload)->id;
        app(FundRates::class)->save('2.00', $operator);
        $retry = $service->checkout($member, $payload);
        $this->assertSame($orderId, $retry->id);
        $this->assertSame(375, $retry->amount_cents);
        $this->assertSame(17, $member->refresh()->points_balance);
        $this->assertDatabaseCount('point_entries', 1);
        $path = '/api/v1/wallet/entries?direction=debit&platform_id=';
        $this->getJson($path.$binding->platform_id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.related.id', $orderId)->assertJsonPath('data.0.related.status', 'pending');
        $this->getJson($path.($binding->platform_id + 1))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_approval_is_idempotent_and_an_unsent_task_requires_operator_resume_after_member_disable(): void
    {
        [, $binding, $operator] = $this->fixture();
        $order = $this->makeOrder($binding);
        $service = app(PlatformFunds::class);
        $this->actingAs($operator, 'web')->get('/admin/platform-fund-orders')->assertOk();
        Livewire::test(ListPlatformFundOrders::class)
            ->callAction(TestAction::make('approve')->table($order))->assertHasNoActionErrors();
        $service->approve($order, $operator);
        $this->assertDatabaseCount('platform_fund_tasks', 1);
        Queue::fake();
        $this->artisan('funds:dispatch')->assertSuccessful();
        Queue::assertPushed(DeliverPlatformFunds::class, 1);
        $member = Member::findOrFail($order->member_id);
        $member->changeDisabledStatus(true);
        $member->changeDisabledStatus(false);

        $worker = app(FundDelivery::class);
        $worker->process($order->task()->sole()->id);
        $this->assertNull($order->task()->sole()->send_started_at);
        $this->assertNotNull($order->task()->sole()->paused_at);
        $service->resume($order, $operator);
        $worker->process($order->task()->sole()->id);
        $worker->process($order->task()->sole()->id);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertDatabaseCount('point_entries', 1);
    }

    public function test_unknown_send_goes_to_lookup_even_after_disable_and_only_explicit_failure_refunds(): void
    {
        [$member, $binding, $operator] = $this->fixture();
        $channel = new class implements FundChannel
        {
            public int $sent = 0;

            public int $lookups = 0;

            public function isAvailable(Platform $platform): bool
            {
                return true;
            }

            public function send(PlatformFundOrder $order): FundResult
            {
                $this->sent++;

                return new FundResult('unknown');
            }

            public function lookup(PlatformFundOrder $order): FundResult
            {
                $this->lookups++;

                return new FundResult('failed');
            }
        };
        $this->app->instance(FundChannel::class, $channel);
        $order = $this->makeOrder($binding);
        app(PlatformFunds::class)->approve($order, $operator);
        $worker = app(FundDelivery::class);
        $task = $order->task()->sole();
        $worker->process($task->id);
        $this->assertSame('unresolved', $order->refresh()->status);
        $this->assertSame(17, $member->refresh()->points_balance);

        $member->changeDisabledStatus(true);
        $this->travel(5)->minutes();
        $worker->process($task->id);
        $worker->process($task->id);
        $this->assertSame(1, $channel->sent);
        $this->assertSame(1, $channel->lookups);
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(20, $member->refresh()->points_balance);
        $this->assertDatabaseCount('point_entries', 2);
    }

    public function test_due_tasks_run_through_the_database_queue(): void
    {
        [, $binding, $operator] = $this->fixture();
        config(['queue.default' => 'database']);
        $order = $this->makeOrder($binding);
        app(PlatformFunds::class)->approve($order, $operator);

        $this->artisan('funds:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_cash_exchange_is_manually_completed_in_production_without_a_delivery_task(): void
    {
        [$member, $binding, $operator] = $this->fixture();
        $this->app->instance('env', 'production');
        $member->forceFill(['available_balance_cents' => 1000])->save();
        $request = ['binding_id' => $binding->id, 'amount_cents' => 300, 'request_id' => (string) Str::uuid()];
        $this->withSession(['_token' => 'fixture-csrf']);
        $request['_token'] = 'fixture-csrf';

        $this->postJson('/api/v1/platform-funds/preview', $request)->assertOk()
            ->assertJsonPath('data.amount_cents', 300);
        $id = $this->postJson('/api/v1/platform-funds/orders', $request)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->json('data.id');

        $order = PlatformFundOrder::findOrFail($id);
        $this->actingAs($operator, 'web')->get('/admin/platform-fund-orders')->assertOk();
        Livewire::test(ListPlatformFundOrders::class)
            ->callAction(TestAction::make('approve')->table($order))->assertHasNoActionErrors();
        app(PlatformFunds::class)->approve($order, $operator);

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame($operator->id, $order->reviewer_id);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(700, $member->refresh()->available_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertDatabaseEmpty('platform_fund_tasks');
        $this->assertDatabaseHas('platform_fund_events', [
            'platform_fund_order_id' => $id, 'user_id' => $operator->id, 'event' => 'manual_paid',
        ]);
        $this->assertDatabaseCount('platform_fund_events', 3);
        $this->actingAs($member, 'member')->getJson('/api/v1/platform-funds/orders/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_cash_rejection_retains_the_debit_and_cancel_only_refunds_pending_orders(): void
    {
        [$member, $binding, $operator] = $this->fixture();
        $member->forceFill(['available_balance_cents' => 2100])->save();
        $request = ['binding_id' => $binding->id, 'amount_cents' => 1575, 'request_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/platform-funds/preview', [...$request, 'amount_cents' => 2101])->assertConflict();
        $this->postJson('/api/v1/platform-funds/preview', $request)->assertOk()
            ->assertJsonPath('data.balance_paid_cents', 1575)->assertJsonPath('data.amount_cents', 1575);
        $id = $this->postJson('/api/v1/platform-funds/orders', $request)->assertCreated()
            ->assertJsonPath('data.balance_paid_cents', 1575)->assertJsonPath('data.rate_version', null)->json('data.id');
        $this->postJson('/api/v1/platform-funds/orders', $request)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/platform-funds/orders', [...$request, 'amount_cents' => 1576])->assertConflict();
        $this->assertSame(525, $member->refresh()->available_balance_cents);

        $order = PlatformFundOrder::findOrFail($id);
        $funds = app(PlatformFunds::class);
        $funds->reject($order, '订单无法受理', $operator);
        $funds->reject($order, '订单无法受理', $operator);
        $this->assertSame(525, $member->refresh()->available_balance_cents);
        $this->assertSame(20, $member->points_balance);
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertDatabaseEmpty('platform_fund_tasks');
        $this->assertDatabaseEmpty('point_entries');
        $this->getJson('/api/v1/platform-funds/orders/'.$id)->assertOk()->assertJsonPath('data.payment_refunded', false);

        $secondId = $this->postJson('/api/v1/platform-funds/orders', [...$request,
            'amount_cents' => 425, 'request_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $second = PlatformFundOrder::findOrFail($secondId);
        $this->assertSame(100, $member->refresh()->available_balance_cents);
        $funds->cancel($second, $operator);
        $funds->cancel($second, $operator);
        $this->assertSame('cancelled', $second->refresh()->status);
        $this->assertSame(525, $member->refresh()->available_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 3);
        $this->assertDatabaseEmpty('platform_fund_tasks');
        $this->getJson('/api/v1/platform-funds/orders/'.$secondId)->assertOk()->assertJsonPath('data.payment_refunded', true);
        $this->assertThrows(fn () => $funds->cancel($order, $operator), HttpException::class);
    }

    private function makeOrder(PlatformBinding $binding): PlatformFundOrder
    {
        $payload = ['binding_id' => $binding->id, 'points' => 3, 'request_id' => (string) Str::uuid()];
        $member = Member::findOrFail($binding->member_id);
        $service = app(PlatformFunds::class);
        $payload['rate_version'] = $service->preview($member, $payload)['rate_version'];

        return $service->checkout($member, $payload);
    }

    private function fixture(): array
    {
        config(['services.platform_funds.driver' => 'simulator']);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $member->forceFill(['points_balance' => 20])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        $platform = Platform::create(['name' => 'Test', 'promotion_url' => 'https://example.test', 'rating' => 5]);
        $platform->forceFill(['active_query_revision' => 1])->save();
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => '4000000001',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0,
            'baseline_observed_at' => now(),
        ]);

        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '资金运营', 'permissions' => [
            'points.manage-rules', 'funds.review', 'funds.resume', 'funds.view',
        ]]));

        return [$member, $binding, $operator];
    }
}
