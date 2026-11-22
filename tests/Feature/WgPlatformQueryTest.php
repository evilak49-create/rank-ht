<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use App\Platforms\PlatformNetwork;
use App\Platforms\PlatformQueryFailure;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WgPlatformQueryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://wg.example.test/api/foreign/openApi/site/user/memberApi/getAccountDetail';

    private const KEY = 'only-for-testing';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_wg_accepts_https_hostnames_without_a_configured_allowlist(): void
    {
        $driver = PlatformNetwork::Wg->queryDriver();
        $settings = $driver->validateConfiguration([...$this->configuration(), 'endpoint' => 'https://yai65.cg.ink/']);

        $this->assertSame(str_replace('wg.example.test', 'yai65.cg.ink', self::ENDPOINT), $settings['endpoint']);
        $settings = $driver->validateConfiguration([...$this->configuration(), 'endpoint' => 'https://new-wg.example.test/']);
        $this->assertSame(str_replace('wg.example.test', 'new-wg.example.test', self::ENDPOINT), $settings['endpoint']);
        Http::assertNothingSent();
    }

    public function test_wg_rejects_a_local_address_even_without_a_domain_allowlist(): void
    {
        $this->expectException(ValidationException::class);

        PlatformNetwork::Wg->queryDriver()->validateConfiguration([
            ...$this->configuration(), 'endpoint' => 'https://127.0.0.1/',
        ]);
    }

    public function test_interface_test_preserves_and_queries_a_non_phone_account(): void
    {
        $driver = PlatformNetwork::Wg->queryDriver();
        $account = '推广 Test+_@01';
        $configuration = $driver->validateConfiguration([...$this->configuration(), 'test_phone' => $account]);
        $this->assertSame($account, $configuration['test_phone']);
        $this->fakeResponse(['total' => 1, 'list' => [[
            'useridx' => 4000000001, 'currency' => 'BRL', 'totalDeposit' => 0, 'validBet' => 0,
        ]]]);
        $this->assertSame('4000000001', $driver->lookupTestAccount($configuration)?->id);
        Http::assertSent(function (Request $request) use ($account): bool {
            $plain = openssl_decrypt(base64_decode($request['reqData'], true), 'aes-128-ecb', self::KEY, OPENSSL_RAW_DATA);

            return json_decode($plain, true, flags: JSON_THROW_ON_ERROR) === ['siteCode' => '0025', 'username' => $account];
        });
    }

    public function test_wg_uses_its_own_configuration_and_binds_with_documented_cumulative_amounts(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => 'WG 操作员', 'permissions' => [
            'platforms.view', 'platforms.edit', 'platforms.configure-query', 'platforms.test-query',
        ]]));
        $service = app(PlatformAdministration::class);
        $this->actingAs($operator, 'web');
        Livewire::test(ListPlatforms::class)->callAction('create_platform', [
            'network' => 'wg', 'name' => 'WG test', 'promotion_url' => 'https://example.test/register', 'rating' => '4.8',
        ] + $this->configuration())->assertHasNoActionErrors();
        $platform = Platform::query()->sole();
        $this->assertSame(self::ENDPOINT, $platform->draft_query['endpoint']);
        $this->assertSame('wg', $platform->network->value);
        $this->get('/admin/platforms')->assertOk()->assertSee('WG');
        Livewire::test(ListPlatforms::class)->mountAction(TestAction::make('configure_query')->table($platform))
            ->assertActionDataSet(['site_code' => '0025', 'api_key' => 'test-merchant']);

        $this->fakeResponse(['result' => 0, 'accountDetailInfo' => [
            'userIdx' => 4000000001, 'siteCode' => '0025',
            'totalPayAmount' => '1234.56', 'finalDepositAmount' => 50, 'validBet' => 7654.321,
            'email' => 'ignored@example.test',
        ]]);
        $this->assertSame('passed', $service->testQuery($platform, $operator));
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password', 'phone_verified_at' => now()]);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $bindingId = $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => $member->phone])
            ->assertCreated()->assertJsonPath('data.platform_account_id', '4000000001')->assertDontSee('ignored@example.test')->json('data.id');
        $this->assertDatabaseHas('platform_bindings', [
            'platform_id' => $platform->id, 'baseline_deposit_cents' => 123456, 'baseline_valid_bet_cents' => 765432,
        ]);

        $this->postJson("/api/v1/platform-bindings/{$bindingId}/point-quotes")->assertCreated()
            ->assertJsonPath('data.observed_deposit_cents', 123456)->assertJsonPath('data.observed_valid_bet_cents', 765432)
            ->assertJsonPath('data.eligibility', 'no_deposit');

        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('test-merchant', $request['apiKey']);
            $this->assertMatchesRegularExpression('/^[0-9]{10}$/', (string) $request['reqTime']);
            $this->assertSame(
                md5("apiKey=test-merchant&reqData={$request['reqData']}&reqTime={$request['reqTime']}&secretKey=".self::KEY),
                $request['sign'],
            );
            $plain = openssl_decrypt(base64_decode($request['reqData'], true), 'aes-128-ecb', self::KEY, OPENSSL_RAW_DATA);
            $this->assertSame(['siteCode' => '0025', 'mobilePhone' => '11912345678'], json_decode($plain, true, flags: JSON_THROW_ON_ERROR));

            return true;
        });
    }

    public function test_wg_does_not_treat_an_incomplete_money_response_as_zero(): void
    {
        $this->fakeResponse(['result' => 0, 'accountDetailInfo' => [
            'userIdx' => 4000000001, 'siteCode' => '0025', 'totalPayAmount' => '0',
        ]]);
        $driver = PlatformNetwork::from('wg')->queryDriver();
        try {
            $driver->lookup($driver->validateConfiguration($this->configuration()), '11912345678');
            $this->fail('Missing valid wager total must fail the lookup.');
        } catch (PlatformQueryFailure $failure) {
            $this->assertSame('schema', $failure->kind);
        }
    }

    public function test_wg_reports_ip_allowlist_rejection_for_the_live_gateway_code(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'code' => 1116001004, 'success' => false, 'failed' => true, 'data' => 'gateway error',
        ])]);
        $driver = PlatformNetwork::Wg->queryDriver();

        try {
            $driver->lookupTestAccount($driver->validateConfiguration($this->configuration()));
            $this->fail('The gateway explicitly rejected the source IP.');
        } catch (PlatformQueryFailure $failure) {
            $this->assertSame('ip_not_allowed', $failure->kind);
            $this->assertSame('WG 出口 IP 不在白名单范围，请联系 WG 核对站点配置', $failure->getMessage());
        }
    }

    public function test_wg_interface_test_can_capture_the_original_response_body(): void
    {
        $body = "{\n  \"code\":1116001004, \"data\":\"trace-id\", \"failed\":true\n}";
        Http::fake([self::ENDPOINT => Http::response($body)]);
        $driver = PlatformNetwork::Wg->queryDriver();
        $captured = null;

        try {
            $driver->lookupTestAccount($driver->validateConfiguration($this->configuration()), function (string $response) use (&$captured): void {
                $captured = $response;
            });
            $this->fail('The gateway explicitly rejected the source IP.');
        } catch (PlatformQueryFailure $failure) {
            $this->assertSame('ip_not_allowed', $failure->kind);
        }

        $this->assertSame($body, $captured);
    }

    public function test_admin_query_notification_shows_the_provider_body_or_explains_its_absence(): void
    {
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => 'WG diagnostics', 'permissions' => [
            'platforms.view', 'platforms.configure-query', 'platforms.test-query',
        ]]));
        $platform = (new Platform)->forceFill([
            'network' => 'wg',
            'name' => 'WG test',
            'promotion_url' => 'https://example.test/register',
            'rating' => '5.0',
            'draft_query' => PlatformNetwork::Wg->queryDriver()->validateConfiguration($this->configuration()),
        ]);
        $platform->save();
        $this->actingAs($operator, 'web');

        $body = '{"code":1116001004,"data":"'.str_repeat('pJL2', 5500).'</textarea><script>trace</script>"}';
        Http::fake([self::ENDPOINT => Http::sequence()->push($body)->push('', 502)]);
        $action = TestAction::make('test_query')->table($platform);

        Livewire::test(ListPlatforms::class)->callAction($action)
            ->assertNotified(Notification::make()->danger()->persistent()
                ->title(PlatformQueryFailure::label('ip_not_allowed'))
                ->view('filament.platforms.query-response', ['responseBody' => $body]));
        $notification = Notification::make()->danger()->persistent()->title('测试结果')
            ->view('filament.platforms.query-response', ['responseBody' => $body]);
        $html = Notification::fromArray($notification->toArray())->toHtml();
        $this->assertStringContainsString(e($body), $html);
        $this->assertStringNotContainsString('<script>trace</script>', $html);
        Livewire::test(ListPlatforms::class)->callAction($action)
            ->assertNotified(Notification::make()->danger()->persistent()
                ->title(PlatformQueryFailure::label('transient'))->body('服务商返回了空响应（HTTP 502）。'));
    }

    private function configuration(): array
    {
        return [
            'endpoint' => 'https://wg.example.test', 'site_code' => '0025', 'api_key' => 'test-merchant',
            'secret_key' => self::KEY, 'test_phone' => '11912345678',
        ];
    }

    private function fakeResponse(array $data): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'code' => 0, 'success' => true,
            'data' => base64_encode(openssl_encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'aes-128-ecb', self::KEY, OPENSSL_RAW_DATA)),
        ])]);
    }
}
