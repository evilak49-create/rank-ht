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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformFundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_changes_require_confirmation_and_checkout_retries_return_the_original_order(): void
    {
        [$member, $binding, $operator] = $this->fixture();
        $payload = ['binding_id' => $binding->id, 'points' => 3];
        $version = $this->postJson('/api/v1/platform-funds/preview', $payload)->assertOk()->assertJsonPath('data.amount_cents', 300)->json('data.rate_version');
        app(FundRates::class)->save('1.25', $operator);
        $payload += ['request_id' => (string) Str::uuid(), 'rate_version' => $version];
        $latest = $this->postJson('/api/v1/platform-funds/orders', $payload)->assertConflict()
            ->assertJsonPath('code', 'rate_changed')->assertJsonPath('data.amount_cents', 375)->json('data.rate_version');
        $this->assertSame(20, $member->refresh()->points_balance);

        $payload['rate_version'] = $latest;
        $orderId = $this->postJson('/api/v1/platform-funds/orders', $payload)->assertCreated()->json('data.id');
        app(FundRates::class)->save('2.00', $operator);
        $this->postJson('/api/v1/platform-funds/orders', $payload)->assertCreated()
            ->assertJsonPath('data.id', $orderId)->assertJsonPath('data.amount_cents', 375);
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

    public function test_production_cannot_use_the_simulated_channel(): void
    {
        [, $binding] = $this->fixture();
        $this->app->instance('env', 'production');

        $this->withSession(['_token' => 'fixture-csrf'])
            ->postJson('/api/v1/platform-funds/preview', ['binding_id' => $binding->id, 'points' => 3, '_token' => 'fixture-csrf'])
            ->assertServiceUnavailable();
        $this->assertDatabaseCount('platform_fund_orders', 0);
    }

    private function makeOrder(PlatformBinding $binding): PlatformFundOrder
    {
        $payload = ['binding_id' => $binding->id, 'points' => 3, 'request_id' => (string) Str::uuid()];
        $payload['rate_version'] = $this->postJson('/api/v1/platform-funds/preview', $payload)->assertOk()->json('data.rate_version');

        return PlatformFundOrder::findOrFail($this->postJson('/api/v1/platform-funds/orders', $payload)->assertCreated()->json('data.id'));
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
