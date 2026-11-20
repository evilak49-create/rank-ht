<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_only_goes_public_after_the_current_query_config_passes(): void
    {
        $this->getJson('/api/v1/platforms')->assertOk()->assertJsonCount(0, 'data');
        [$platform, $operator] = $this->draft();
        $this->getJson("/api/v1/platforms/{$platform->id}")->assertNotFound();

        $this->fakeUpstream();
        $service = app(PlatformAdministration::class);
        $this->assertSame('passed', $service->testQuery($platform, $operator));
        $this->getJson('/api/v1/platforms?search=Example')
            ->assertOk()->assertJsonPath('data.0.rating', '4.8')
            ->assertJsonMissingPath('data.0.active_query');
        $this->getJson('/api/v1/platforms/featured')->assertOk()->assertJsonPath('data.0.id', $platform->id);
        $this->assertStringNotContainsString('fixture-token', (string) DB::table('platforms')->value('active_query'));

        $service->saveQuery($platform, [...$this->queryConfig(), 'token' => 'replacement-token'], $operator);
        $this->fakeFailure(401);
        $this->assertSame('authentication', $service->testQuery($platform, $operator));
        $this->getJson("/api/v1/platforms/{$platform->id}")->assertOk();

        $service->setDisabled($platform, true, $operator);
        $this->getJson("/api/v1/platforms/{$platform->id}")->assertNotFound();
        $this->getJson('/api/v1/platforms/featured')->assertJsonCount(0, 'data');
    }

    public function test_a_verified_member_binds_one_account_and_keeps_the_initial_baseline(): void
    {
        [$platform, $operator] = $this->draft();
        $this->fakeUpstream();
        $this->assertSame('passed', app(PlatformAdministration::class)->testQuery($platform, $operator));
        $first = Member::create(['phone' => '11912345678', 'password' => 'member-password', 'phone_verified_at' => now()]);
        $second = Member::create(['phone' => '11912345679', 'password' => 'member-password', 'phone_verified_at' => now()]);

        $this->actingAs($first, 'member')->withSession(['member_session_version' => 0]);
        $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => '11912345679'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => '+55 (11) 91234-5678'])
            ->assertCreated()->assertJsonPath('data.platform_account_id', '4000000001');
        $this->getJson('/api/v1/platform-bindings')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('platform_bindings', [
            'member_id' => $first->id, 'platform_id' => $platform->id,
            'baseline_deposit_cents' => 1000, 'baseline_valid_bet_cents' => 2000,
        ]);

        $this->actingAs($second, 'member');
        $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => $second->phone])->assertConflict();
        $this->assertDatabaseCount('platform_bindings', 1);
    }

    public function test_an_outdated_test_result_cannot_publish_a_replacement_configuration(): void
    {
        [$platform, $operator] = $this->draft();
        $service = app(PlatformAdministration::class);
        Http::fake(function () use ($platform, $operator, $service) {
            $service->saveQuery($platform, [...$this->queryConfig(), 'token' => 'newer-token'], $operator);

            return Http::response($this->accountResponse());
        });

        $this->assertSame('stale', $service->testQuery($platform, $operator));
        $this->assertNull($platform->refresh()->active_query_revision);
        $this->assertSame(2, $platform->query_revision);
        $this->getJson("/api/v1/platforms/{$platform->id}")->assertNotFound();
    }

    public function test_binding_candidates_filter_only_the_current_members_platforms_before_pagination(): void
    {
        $url = '/api/v1/platforms/bindable';
        $this->getJson($url)->assertUnauthorized();
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $other = Member::create(['phone' => '11912345679', 'password' => 'member-password']);
        for ($index = 1; $index <= 21; $index++) {
            $platform = Platform::create([
                'name' => sprintf('Example %02d', $index), 'promotion_url' => 'https://promotion.example.test/register', 'rating' => '4.8',
            ]);
            $platform->forceFill(['active_query_revision' => 1])->save();
            $owner = $index <= 20 ? $member : $other;
            PlatformBinding::create([
                'member_id' => $owner->id, 'platform_id' => $platform->id, 'platform_account_id' => (string) $index,
                'query_phone' => $owner->phone, 'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0,
                'baseline_observed_at' => now(),
            ]);
        }

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($url.'?search=Example')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Example 21');
        $this->getJson($url.'?search=Example%2001')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/platforms')->assertOk()->assertJsonPath('total', 21);
        $this->getJson('/api/v1/platform-bindings')->assertOk()->assertJsonPath('total', 20);
    }

    public function test_three_transient_query_failures_hide_the_platform_until_a_manual_retest(): void
    {
        [$platform, $operator] = $this->draft();
        $this->fakeUpstream();
        $service = app(PlatformAdministration::class);
        $service->testQuery($platform, $operator);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password', 'phone_verified_at' => now()]);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        $this->fakeFailure(503);
        for ($request = 0; $request < 3; $request++) {
            $this->postJson("/api/v1/platforms/{$platform->id}/binding", ['phone' => $member->phone])->assertServiceUnavailable();
        }
        $this->getJson("/api/v1/platforms/{$platform->id}")->assertNotFound();
        $this->fakeUpstream();
        $this->assertSame('passed', $service->testQuery($platform, $operator));
        $this->getJson("/api/v1/platforms/{$platform->id}")->assertOk();
    }

    public function test_an_alternate_phone_uses_a_single_use_binding_code_without_changing_the_login_phone(): void
    {
        [$platform, $operator] = $this->draft();
        $this->fakeUpstream();
        app(PlatformAdministration::class)->testQuery($platform, $operator);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password', 'phone_verified_at' => now()]);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        Log::spy();

        $phone = '11912345679';
        $url = "/api/v1/platforms/{$platform->id}/binding";
        $this->postJson($url.'/code', ['phone' => $phone])->assertAccepted()->assertJsonMissingPath('code');
        $code = null;
        Log::shouldHaveReceived('debug')->once()->withArgs(function (string $message, array $context) use (&$code, $phone): bool {
            $code = $context['code'] ?? null;

            return $message === 'Local SMS simulation' && $context['phone'] === $phone && is_string($code);
        });

        $this->postJson($url, ['phone' => $phone, 'code' => $code])->assertCreated();
        $this->assertDatabaseMissing('sms_challenges', ['phone' => $phone]);
        $this->assertSame('11912345678', $member->refresh()->phone);
        $this->assertDatabaseHas('platform_bindings', ['member_id' => $member->id, 'query_phone' => $phone]);
    }

    public function test_filament_operators_can_create_a_platform_and_activate_its_tested_configuration(): void
    {
        [, $operator] = $this->draft();
        $this->actingAs($operator, 'web')->get('/admin/platforms')->assertOk();
        $this->fakeUpstream();

        Livewire::test(ListPlatforms::class)
            ->callAction('create_platform', [
                'name' => 'New Platform', 'promotion_url' => 'https://promotion.example.test/start', 'rating' => '5.0',
            ])->assertHasNoActionErrors();

        $platform = Platform::query()->where('name', 'New Platform')->sole();
        $action = TestAction::make('configure_query')->table($platform);
        Livewire::test(ListPlatforms::class)->mountAction($action)->assertActionDataSet([
            'endpoint' => 'https://api5.v-n-r-1.com/api/backend/trpc/user.list',
            'admin_host' => 'admin5-public.y-7-l-x.com', 'tenant_id' => 9818088, 'region_id' => 1,
        ]);
        Livewire::test(ListPlatforms::class)->callAction($action, [
            ...$this->queryConfig(), 'endpoint' => 'https://denied.example.test/api/backend/trpc/user.list',
        ])->assertHasActionErrors(['endpoint']);
        Livewire::test(ListPlatforms::class)
            ->callAction($action, [
                ...$this->queryConfig(), 'endpoint' => $this->queryConfig()['endpoint'].'?input=%7B%7D',
                'token' => 'Bearer '.$this->queryConfig()['token'],
            ])->assertHasNoActionErrors()->assertNotified('查询接口配置已保存')
            ->callAction(TestAction::make('test_query')->table($platform))->assertHasNoActionErrors();

        $this->assertSame('available', $platform->refresh()->status());
        $this->assertSame($this->queryConfig()['endpoint'], $platform->active_query['endpoint']);
        Livewire::test(ListPlatforms::class)->mountAction($action)->assertActionDataSet($this->queryConfig());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('client-language', 'zh-CN')
            && $request->hasHeader('Content-Type', 'application/json'));
        $this->assertDatabaseHas('platform_audits', ['platform_id' => $platform->id, 'user_id' => $operator->id, 'result' => 'passed']);
    }

    public function test_filament_platform_configuration_is_hidden_from_read_only_staff(): void
    {
        [$platform, $operator] = $this->draft();
        $role = AdminRole::create(['name' => '只读平台', 'permissions' => ['platforms.view']]);
        $operator->roles()->sync([$role->id]);

        $this->actingAs($operator, 'web')->get('/admin/platforms')->assertOk()->assertDontSee('fixture-token');
        Livewire::test(ListPlatforms::class)
            ->assertActionHidden(TestAction::make('configure_query')->table($platform))
            ->assertActionHidden(TestAction::make('test_query')->table($platform));
    }

    private function draft(): array
    {
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '平台运营', 'permissions' => [
            'platforms.view', 'platforms.edit', 'platforms.configure-query', 'platforms.test-query', 'platforms.change-status',
        ]]));
        $service = app(PlatformAdministration::class);
        $platform = $service->create([
            'name' => 'Example Platform', 'promotion_url' => 'https://promotion.example.test/register',
            'rating' => '4.8', 'featured_position' => 1,
        ], $operator);
        $service->saveQuery($platform, $this->queryConfig(), $operator);

        return [$platform, $operator];
    }

    private function queryConfig(): array
    {
        return [
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'fixture-token', 'account' => 'fixture-account',
            'fingerprint' => 'fixture-fingerprint', 'tenant_id' => 123, 'region_id' => 1,
            'test_phone' => '11912345678',
        ];
    }

    private function fakeUpstream(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->accountResponse())]);
    }

    private function accountResponse(): array
    {
        return [
            'result' => ['data' => ['json' => ['userList' => [
                'total' => 1, 'pageData' => [[
                    'id' => 4000000001, 'historicalPay' => 1000, 'historicalValidBetting' => 2000, 'currency' => 'BRL',
                ]],
            ]]]],
        ];
    }

    private function fakeFailure(int $status): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], $status)]);
    }
}
