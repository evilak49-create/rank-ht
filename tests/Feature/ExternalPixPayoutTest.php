<?php

namespace Tests\Feature;

use App\Filament\Resources\PixWithdrawalResource\Pages\ListPixWithdrawals;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\PixPayoutChannel;
use App\Models\PixWithdrawal;
use App\Models\User;
use App\RedPackets\ExternalPixChannel;
use App\RedPackets\PixDelivery;
use App\RedPackets\PixWithdrawals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExternalPixPayoutTest extends TestCase
{
    use RefreshDatabase;

    public static function providers(): array
    {
        return [
            ['u2cpay', 'merchantId', 'accountNo'],
            ['4zpay', 'merchantNo', 'account'],
        ];
    }

    #[DataProvider('providers')]
    public function test_approval_submits_once_and_query_settles_only_the_matching_order(string $driver, string $merchantField, string $accountField): void
    {
        [$order, $operator, $channel, $config] = $this->fixture($driver);
        $subject = app(PixWithdrawals::class);
        $choice = 'provider:'.$channel->id;
        $this->assertArrayHasKey($choice, $subject->payoutOptions($order));
        $this->assertThrows(fn () => $subject->approve($order, $operator, $choice), ValidationException::class);
        $subject->approve($order, $operator, $choice, 'Nome De Teste');
        $order->refresh();
        $this->assertStringNotContainsString('fixture-secret', (string) DB::table('pix_withdrawals')->value('payout_configuration'));
        $this->assertArrayNotHasKey('payout_configuration', $order->toArray());

        $payload = [$merchantField => 'fixture-merchant', 'merchantOrderNo' => $order->order_number,
            'orderNo' => 'provider-ref', 'status' => 'SUCCESS', 'currency' => 'BRL', 'amount' => 400];
        Http::fake([
            $config['create'] => Http::response(['success' => true, 'data' => $payload]),
            $config['query'] => Http::sequence()
                ->push(['success' => true, 'data' => array_replace($payload, ['amount' => 500])])
                ->push(['success' => true, 'data' => $payload]),
        ])->preventStrayRequests();
        $delivery = app(PixDelivery::class);
        $delivery->process($order->task->id);
        $this->assertSame('unresolved', $order->refresh()->status);
        Http::assertSent(function (Request $request) use ($config, $accountField, $merchantField, $order): bool {
            if ($request->url() !== $config['create']) {
                return false;
            }
            $sent = $request->data();
            $signature = $sent['sign'];
            unset($sent['sign']);

            return $sent[$accountField] === $order->cpf_snapshot && $sent[$merchantField] === 'fixture-merchant'
                && $sent['accountType'] === 'PIX_CPF' && $sent['amount'] === 400
                && $sent['accountName'] === 'Nome De Teste'
                && $signature === ExternalPixChannel::signature($sent, 'fixture-secret');
        });

        $this->travel(6)->minutes();
        $delivery->process($order->task->id);
        $this->assertSame('unresolved', $order->refresh()->status);
        $this->travel(6)->minutes();
        $delivery->process($order->task->id);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(0, $order->member->held_balance_cents);
        Http::assertSentCount(3);
    }

    public function test_signed_and_allowlisted_callback_is_idempotent_and_preserves_the_approved_settings(): void
    {
        [$order, $operator, $channel, $config] = $this->fixture('u2cpay');
        app(PixWithdrawals::class)->approve($order, $operator, 'provider:'.$channel->id, 'Nome De Teste');
        $order->task()->update(['send_started_at' => now()]);
        $channel->update(['configuration' => array_replace($config, ['secret' => 'new-secret'])]);
        $data = ['merchantId' => 'fixture-merchant', 'merchantOrderNo' => $order->order_number,
            'orderNo' => 'provider-ref', 'status' => 'FAILED', 'currency' => 'BRL', 'amount' => 400,
            'errorMsg' => ' Provider declined the payment '];
        $data['sign'] = ExternalPixChannel::signature($data, 'fixture-secret');
        $url = '/api/v1/pix/provider-callback/'.$channel->id;
        $this->postJson($url, $data)->assertForbidden();
        $this->postJson($url, $data, ['CF-Connecting-IP' => '192.0.2.30'])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.30']);
        $this->postJson($url, array_replace($data, ['amount' => 401]))->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '104.16.0.1'])
            ->postJson($url, $data, ['CF-Connecting-IP' => '192.0.2.30'])->assertOk()->assertContent('success');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.30']);
        $this->postJson($url, $data)->assertOk();
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(1000, $order->member->available_balance_cents);
        $this->assertSame(0, $order->member->held_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 2);
    }

    public function test_configuration_form_updates_settings_without_exposing_the_stored_secret(): void
    {
        [, $operator, $channel, $config] = $this->fixture('u2cpay');
        $operator->roles()->firstOrFail()->update(['permissions' => ['pix.view', 'pix.manage-channels']]);
        $this->actingAs($operator, 'web')->get('/admin/pix-withdrawals')->assertOk();
        Livewire::test(ListPixWithdrawals::class)->mountAction('configure_u2cpay')
            ->assertActionDataSet(['merchant' => 'fixture-merchant', 'secret' => null])
            ->setActionData([
                'enabled' => false, 'merchant' => 'updated-merchant', 'secret' => '',
                'create' => $config['create'], 'query' => $config['query'], 'balance' => $config['balance'],
                'callback_ips' => "192.0.2.45\n192.0.2.46", 'min_cents' => 100, 'max_cents' => 1500,
            ])->callMountedAction()->assertHasNoActionErrors();
        $this->assertFalse($channel->refresh()->enabled);
        $this->assertSame('updated-merchant', $channel->configuration['merchant']);
        $this->assertSame('fixture-secret', $channel->configuration['secret']);
        $this->assertSame(['192.0.2.45', '192.0.2.46'], $channel->configuration['callback_ips']);
        $this->assertStringNotContainsString('fixture-secret', (string) DB::table('pix_payout_channels')->value('configuration'));
    }

    private function fixture(string $driver): array
    {
        config(['services.pix.driver' => 'disabled']);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $member->forceFill(['pix_cpf' => '52998224725', 'available_balance_cents' => 1000])->save();
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => 'Pix reviewer', 'permissions' => ['pix.review']]));
        $id = $this->actingAs($member, 'member')->withSession(['member_session_version' => 0])
            ->postJson('/api/v1/cash-wallet/withdrawals', ['request_id' => (string) Str::uuid(), 'amount_cents' => 400])
            ->assertCreated()->json('data.id');
        $definition = ExternalPixChannel::PROVIDERS[$driver];
        $config = ExternalPixChannel::validateConfiguration([
            'driver' => $driver, 'merchant' => 'fixture-merchant', 'secret' => 'fixture-secret',
            'callback_ips' => ['192.0.2.30'], 'min_cents' => 100, 'max_cents' => 1000,
            'create' => 'https://'.$definition['host'].$definition['create'],
            'query' => 'https://'.$definition['host'].$definition['query'],
            'balance' => 'https://'.$definition['host'].$definition['balance'],
        ]);
        $channel = PixPayoutChannel::create(['name' => $definition['label'], 'enabled' => true, 'driver' => $driver, 'configuration' => $config]);

        return [PixWithdrawal::findOrFail($id), $operator, $channel, $config];
    }
}
