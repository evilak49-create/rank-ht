<?php

namespace Tests\Feature;

use App\Filament\Resources\PixWithdrawalResource\Pages\ListPixWithdrawals;
use App\Filament\Resources\RedPacketRoundResource\Pages\ListRedPacketRounds;
use App\Jobs\DeliverPixWithdrawal;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\PixWithdrawal;
use App\Models\User;
use App\RedPackets\InvitationRewards;
use App\RedPackets\PixChannel;
use App\RedPackets\PixDelivery;
use App\RedPackets\PixResult;
use App\RedPackets\PixWithdrawals;
use App\RedPackets\RedPackets;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class RedPacketTest extends TestCase
{
    use RefreshDatabase;

    public function test_red_packets_freeze_once_and_an_arrival_closes_the_original_round(): void
    {
        config(['services.pix.driver' => 'simulator']);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $open = ['request_id' => (string) Str::uuid()];
        $round = $this->postJson('/api/v1/red-packets/open', $open)->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(8500, $round['initial_cents']);
        $this->assertLessThanOrEqual(9500, $round['initial_cents']);
        $this->postJson('/api/v1/red-packets/open', $open)->assertOk()->assertJsonPath('data.id', $round['id']);

        $request = ['request_id' => (string) Str::uuid()];
        $this->putJson('/api/v1/pix-account', ['cpf' => '529.982.247-25'])->assertOk()->assertJsonPath('data.masked_cpf', '***.***.***-25');
        $this->postJson('/api/v1/pix-withdrawals', $request)->assertConflict();

        $invite = $this->getJson('/api/v1/red-packets')->assertOk()->json('data');
        $inviteCode = $invite['invite_code'];
        $this->assertSame('/?invite_code='.$inviteCode.'#register/invite', $invite['invite_url']);
        for ($index = 0; $index < 12; $index++) {
            $invitee = Member::create(['phone' => (string) (11912345000 + $index), 'password' => 'member-password']);
            app(InvitationRewards::class)->register($invitee, $inviteCode);
        }
        $pending = DB::table('red_packet_invites')->whereNull('settled_at')->oldest('id')->first();
        $this->assertNotNull($pending);
        $withdrawal = $this->postJson('/api/v1/pix-withdrawals', $request)->assertCreated()->assertJsonPath('data.amount_cents', 10000)->json('data.id');
        $this->postJson('/api/v1/pix-withdrawals', $request)->assertCreated()->assertJsonPath('data.id', $withdrawal);
        $this->putJson('/api/v1/pix-account', ['cpf' => '11144477735'])->assertOk();
        $order = PixWithdrawal::findOrFail($withdrawal);
        $this->assertSame('52998224725', $order->cpf_snapshot);

        $operator = $this->operator(['pix.review', 'pix.view']);
        app(PixWithdrawals::class)->approve($order, $operator);
        app(PixWithdrawals::class)->approve($order, $operator);
        $task = $order->task()->sole();
        Queue::fake();
        $this->artisan('pix:dispatch')->assertSuccessful();
        Queue::assertPushed(DeliverPixWithdrawal::class, fn (DeliverPixWithdrawal $job) => $job->taskId === $task->id);
        (new DeliverPixWithdrawal($task->id))->handle(app(PixDelivery::class));
        app(PixDelivery::class)->process($task->id);
        $this->getJson("/api/v1/pix-withdrawals/$withdrawal")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('red_packet_rounds', ['id' => $round['id'], 'balance_cents' => 0, 'frozen_cents' => 0]);
        $this->assertDatabaseCount('pix_tasks', 1);
        $this->assertDatabaseCount('point_entries', 0);

        $this->postJson('/api/v1/red-packets/open', $open)->assertOk()->assertJsonPath('data.id', $round['id']);
        $nextRound = $this->postJson('/api/v1/red-packets/open', ['request_id' => (string) Str::uuid()])->assertOk()->json('data.id');
        $this->assertDatabaseCount('red_packet_rounds', 2);
        $this->assertDatabaseHas('red_packet_invites', ['id' => $pending->id, 'red_packet_round_id' => $nextRound]);
        $this->getJson('/api/v1/red-packets')->assertOk()->assertJsonPath('data.invite_code', $inviteCode);
    }

    public function test_timeout_only_queries_and_a_signed_failure_releases_once(): void
    {
        $key = str_repeat('k', 32);
        config(['services.pix.driver' => 'simulator', 'services.pix.simulator_callback_key' => $key]);
        $channel = Mockery::mock(PixChannel::class);
        $channel->shouldReceive('isAvailable')->andReturnTrue();
        $channel->shouldReceive('send')->once()->andThrow(new \RuntimeException('timeout'));
        $channel->shouldReceive('lookup')->once()->andReturn(new PixResult('unknown'));
        $this->app->instance(PixChannel::class, $channel);
        [$member, $order] = $this->readyOrder();
        $operator = $this->operator(['pix.review', 'pix.view', 'pix.resume']);
        $withdrawals = app(PixWithdrawals::class);
        $withdrawals->approve($order, $operator);
        $task = $order->task()->sole();

        $member->changeDisabledStatus(true);
        app(PixDelivery::class)->process($task->id);
        $this->assertNotNull($task->refresh()->paused_at);
        $this->assertNull($task->send_started_at);
        $member->changeDisabledStatus(false);
        $withdrawals->resume($order, $operator);
        app(PixDelivery::class)->process($task->id);
        $this->assertSame('unresolved', $order->refresh()->status);
        $this->assertDatabaseHas('red_packet_rounds', ['id' => $order->red_packet_round_id, 'frozen_cents' => 10000]);

        // In-flight orders are still reconciled after the member is disabled.
        $member->changeDisabledStatus(true);
        $this->travel(6)->minutes();
        app(PixDelivery::class)->process($task->id);

        $payload = ['order_number' => $order->order_number, 'status' => 'failed'];
        $time = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $time.'.'.json_encode($payload), $key);
        $callback = '/api/v1/pix/simulator-callback';
        $this->postJson($callback, $payload, ['X-Pix-Timestamp' => $time, 'X-Pix-Signature' => str_repeat('0', 64)])
            ->assertForbidden();
        $headers = ['X-Pix-Timestamp' => $time, 'X-Pix-Signature' => $signature];
        $this->postJson($callback, $payload, $headers)->assertNoContent();
        $this->postJson($callback, $payload, $headers)->assertNoContent();
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertDatabaseHas('red_packet_rounds', [
            'id' => $order->red_packet_round_id, 'balance_cents' => 10000, 'frozen_cents' => 0, 'closed_at' => null,
        ]);
        $this->assertSame(1, DB::table('red_packet_entries')->where('pix_withdrawal_id', $order->id)->where('kind', 'released')->count());
    }

    public function test_admin_permissions_and_threshold_versions_apply_to_real_panel_actions(): void
    {
        config(['services.pix.driver' => 'simulator']);
        [, $order] = $this->readyOrder();
        $operator = $this->operator(['red-packets.view', 'pix.view']);
        $this->actingAs($operator, 'web');
        $this->get('/admin/red-packet-rounds')->assertOk();
        $this->get('/admin/red-packet-invites')->assertOk();
        $this->get('/admin/pix-withdrawals')->assertOk()->assertDontSee('52998224725');

        $reject = TestAction::make('reject')->table($order);
        $cpf = TestAction::make('show_cpf')->table($order);
        Livewire::test(ListPixWithdrawals::class)->assertActionHidden($reject)->assertActionHidden($cpf);
        Livewire::test(ListRedPacketRounds::class)->assertActionHidden('configure_threshold');
        $operator->roles()->attach(AdminRole::create(['name' => '审批管理', 'permissions' => ['pix.review', 'red-packets.manage']]));
        $modal = Livewire::test(ListPixWithdrawals::class)->mountAction($cpf)->instance()->getMountedAction();
        $this->assertSame('52998224725', $modal?->getModalDescription());
        $this->assertDatabaseHas('pix_events', ['pix_withdrawal_id' => $order->id, 'user_id' => $operator->id, 'event' => 'cpf_viewed']);
        Livewire::test(ListRedPacketRounds::class)->callAction('configure_threshold', ['threshold' => '125.50'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('red_packet_rounds', ['id' => $order->red_packet_round_id, 'threshold_cents' => 10000]);
        $new = Member::create(['phone' => '11900000555', 'password' => 'member-password']);
        $this->assertSame(12550, app(RedPackets::class)->open($new->refresh(), (string) Str::uuid())->threshold_cents);
        Livewire::test(ListPixWithdrawals::class)->callAction($reject, ['reason' => '核对未通过'])->assertHasNoActionErrors();
        $this->assertSame('rejected', $order->refresh()->status);
        $this->assertDatabaseHas('red_packet_rounds', ['id' => $order->red_packet_round_id, 'frozen_cents' => 0, 'closed_at' => null]);
    }

    public function test_verified_invitation_registration_and_the_ten_cent_boundary(): void
    {
        $owner = Member::create(['phone' => '11900000555', 'password' => 'member-password'])->refresh();
        $packets = app(RedPackets::class);
        $code = $packets->inviteCode($owner);
        $round = $packets->open($owner, (string) Str::uuid());
        $round->forceFill(['balance_cents' => 9990])->save();
        Log::spy();
        $this->postJson('/api/v1/auth/sms-code', ['phone' => '11912345678', 'purpose' => 'register'])->assertAccepted();
        $sms = null;
        Log::shouldHaveReceived('debug')->once()->withArgs(function (string $message, array $context) use (&$sms): bool {
            $sms = $context['code'] ?? null;

            return $message === 'Local SMS simulation' && is_string($sms);
        });
        $payload = ['phone' => '11912345678', 'password' => 'member-password', 'code' => $sms];
        $this->postJson('/api/v1/auth/register', $payload + ['invite_code' => str_repeat('a', 32)])
            ->assertUnprocessable()->assertJsonValidationErrors('invite_code');
        $memberId = $this->postJson('/api/v1/auth/register', $payload + ['invite_code' => $code])->assertCreated()->json('id');
        $invitee = Member::findOrFail($memberId);
        app(InvitationRewards::class)->register($invitee, $code);
        $first = (int) DB::table('red_packet_invites')->where('invitee_id', $memberId)->value('reward_cents');
        $this->assertGreaterThanOrEqual(5, $first);
        $this->assertLessThanOrEqual(7, $first);
        $next = Member::create(['phone' => '11900000666', 'password' => 'member-password']);
        app(InvitationRewards::class)->register($next, $code);
        $this->assertDatabaseHas('red_packet_invites', ['invitee_id' => $next->id, 'reward_cents' => 10 - $first]);
        $this->assertSame(10000, $round->refresh()->balance_cents);
        $this->assertDatabaseCount('red_packet_invites', 2);
    }

    public function test_production_disables_the_simulator(): void
    {
        config(['services.pix.driver' => 'simulator', 'services.pix.simulator_callback_key' => str_repeat('k', 32)]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0, '_token' => 'test-csrf-token']);
        $this->app->instance('env', 'production');
        $this->postJson('/api/v1/pix-withdrawals', ['request_id' => (string) Str::uuid(), '_token' => 'test-csrf-token'])
            ->assertServiceUnavailable();
        $this->postJson('/api/v1/pix/simulator-callback')->assertNotFound();
        $this->assertDatabaseEmpty('pix_withdrawals');
    }

    private function readyOrder(): array
    {
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password'])->refresh();
        $packets = app(RedPackets::class);
        $round = $packets->open($member, (string) Str::uuid());
        $round->forceFill(['balance_cents' => 10000])->save();
        $packets->saveCpf($member, '52998224725');

        return [$member, app(PixWithdrawals::class)->submit($member, (string) Str::uuid())];
    }

    private function operator(array $permissions): User
    {
        $user = User::factory()->create();
        $user->forceFill(['panel_enabled' => true])->save();
        $user->roles()->attach(AdminRole::create(['name' => 'Pix 财务', 'permissions' => $permissions]));

        return $user;
    }
}
