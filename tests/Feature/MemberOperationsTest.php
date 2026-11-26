<?php

namespace Tests\Feature;

use App\Auth\SmsChallenges;
use App\Auth\SmsSender;
use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Jobs\ResolveRegistrationCountry;
use App\Members\MemberAdministration;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformBindingAttempt;
use App\Models\User;
use App\Platforms\PlatformAccount;
use App\Platforms\PlatformQueryClient;
use App\Platforms\PlatformRecharges;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MemberOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_operations_migration_resumes_when_the_intent_foreign_key_is_missing(): void
    {
        Schema::table('platform_registration_intents', function (Blueprint $table): void {
            $table->dropForeign(['platform_binding_attempt_id']);
        });
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $migration = require database_path('migrations/2026_11_24_010100_add_member_operations.php');

        $migration->up();
        $migration->up();

        $foreign = collect(Schema::getForeignKeys('platform_registration_intents'))
            ->where('columns', ['platform_binding_attempt_id'])->sole();
        $this->assertSame('platform_binding_attempts', $foreign['foreign_table']);
        $this->assertSame('set null', $foreign['on_delete']);
        $this->assertDatabaseHas('members', ['id' => $member->id, 'phone' => '11912345678']);
    }


    public function test_operator_can_adjust_available_cash_once_and_see_masked_account(): void
    {
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '运营', 'permissions' => ['members.view', 'members.adjust-cash']]));
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $member->forceFill(['available_balance_cents' => 500, 'held_balance_cents' => 800, 'pix_cpf' => '52998224725'])->save();

        $service = app(MemberAdministration::class);
        $data = ['amount_cents' => 200, 'direction' => 'debit', 'reason' => '现金更正', 'operation_key' => (string) Str::uuid()];
        $this->assertSame($service->adjustCash($member, $data, $operator), $service->adjustCash($member, $data, $operator));
        $this->assertDatabaseHas('cash_balance_entries', [
            'member_id' => $member->id, 'source' => 'manual_adjustment', 'amount_cents' => -200, 'balance_after_cents' => 300,
        ]);
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertDatabaseHas('admin_audits', ['member_id' => $member->id, 'user_id' => $operator->id, 'action' => 'member.cash_adjusted']);
        $this->assertSame(800, $member->refresh()->held_balance_cents);
        $this->actingAs($operator, 'web')->get("/admin/members/{$member->id}")->assertOk()
            ->assertSee('收款账户')->assertDontSee('52998224725');
        Livewire::test(ViewMember::class, ['record' => $member->id])
            ->callAction('adjust_cash', data: ['amount_cents' => '1,25', 'direction' => 'credit', 'reason' => '补发现金'])
            ->assertHasNoActionErrors();
        $this->assertSame(425, $member->refresh()->available_balance_cents);
    }

    public function test_guest_uses_sms_reset_under_cloudflare_registration_mode(): void
    {
        config(['services.turnstile.site_key' => 'public', 'services.turnstile.secret' => 'private']);
        DB::table('member_verification_settings')->where('id', 1)->update(['mode' => 'cloudflare']);
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $this->getJson('/api/v1/auth/verification')->assertOk()
            ->assertJsonPath('data.mode', 'cloudflare')->assertJsonPath('data.reset_mode', 'sms')
            ->assertJsonPath('data.turnstile_site_key', 'public')->assertJsonMissing(['private']);

        $code = null;
        $sender = $this->mock(SmsSender::class);
        $sender->shouldReceive('sendCode')->once()->withArgs(function ($phone, $purpose, $value) use (&$code): bool {
            $code = $value;

            return $phone === '11912345678' && $purpose === 'reset_password';
        });
        $this->app->instance(SmsChallenges::class, new SmsChallenges($sender));
        $this->postJson('/api/v1/auth/sms-code', ['phone' => $member->phone, 'purpose' => 'reset_password'])->assertAccepted();
        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => $member->phone, 'password' => 'new-password', 'code' => $code,
        ])->assertNoContent();
        $this->assertTrue(Hash::check('new-password', $member->refresh()->password));
    }

    public function test_registration_limits_are_cumulative_and_failed_registrations_use_no_slot(): void
    {
        config(['services.turnstile.site_key' => 'public', 'services.turnstile.secret' => 'private']);
        DB::table('member_verification_settings')->where('id', 1)->update([
            'mode' => 'cloudflare', 'ip_registration_limit' => 2, 'device_registration_limit' => 1,
        ]);
        Http::fakeSequence('https://challenges.cloudflare.com/turnstile/v0/siteverify')
            ->push(['success' => false])->push(['success' => true, 'action' => 'register'])
            ->push(['success' => true, 'action' => 'register'])->push(['success' => true, 'action' => 'register'])
            ->push(['success' => true, 'action' => 'register']);
        $browser = $this->getJson('/api/v1/auth/verification')->assertOk()->getCookie('member_registration_device');
        $this->withCredentials()->withCookie($browser->getName(), $browser->getValue());
        $body = ['phone' => '11912345678', 'password' => 'test-password', 'turnstile_token' => 'fixture-token'];
        $this->postJson('/api/v1/auth/register', $body)->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/v1/auth/register', $body)->assertCreated();
        $this->travel(2)->days();
        $this->postJson('/api/v1/auth/register', [...$body, 'phone' => '11912345679', 'turnstile_token' => 'next-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseHas('members', ['phone' => '11912345678', 'registration_ip' => '127.0.0.1']);

        $this->withCookie($browser->getName(), Str::random(64));
        $this->postJson('/api/v1/auth/register', [...$body, 'phone' => '11912345679', 'turnstile_token' => 'third-token'])->assertCreated();
        $this->withCookie($browser->getName(), Str::random(64));
        $this->postJson('/api/v1/auth/register', [...$body, 'phone' => '11912345670', 'turnstile_token' => 'fourth-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_auto_binding_displays_timeout_and_late_callback_closes_the_same_attempt(): void
    {
        config(['services.platform_query.allowed_hosts' => ['partner.example.test']]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $platform = Platform::create([
            'name' => 'Registered Site', 'promotion_url' => 'https://site.example.test', 'rating' => 5, 'guarantee_amount_brl' => 100,
        ]);
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => [
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'fixture', 'account' => 'fixture', 'fingerprint' => 'fixture',
            'region_id' => 1, 'tenant_id' => 10,
        ]])->save();
        Http::fake(['partner.example.test/*' => Http::response(['result' => ['data' => ['json' => ['userList' => [
            'total' => 1, 'pageData' => [['id' => 4000000001, 'historicalPay' => 100, 'historicalValidBetting' => 300, 'currency' => 'BRL']],
        ]]]]])]);

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0])
            ->postJson("/api/v1/platforms/{$platform->id}/registration")->assertOk();
        $attempt = PlatformBindingAttempt::query()->sole();
        $this->assertSame('processing', $attempt->displayStatus());
        $this->travel(45)->minutes();
        $this->assertSame('timed_out', $attempt->displayStatus());
        $this->getJson('/api/v1/platform-autobind?'.http_build_query(['bd_id' => $member->phone, 'platform' => $platform->name]))->assertOk();
        $this->assertDatabaseCount('platform_binding_attempts', 1);
        $this->assertSame('succeeded', $attempt->refresh()->displayStatus());
        $this->assertNotNull($attempt->platform_binding_id);
        $this->assertDatabaseHas('platform_bindings', ['id' => $attempt->platform_binding_id, 'protection_status' => 'active']);
    }

    public function test_registration_ip_and_country_are_read_only_from_trusted_edges(): void
    {
        Queue::fake();
        config([
            'services.turnstile.site_key' => 'public', 'services.turnstile.secret' => 'private',
            'services.registration.trusted_edge_proxies' => ['203.0.113.0/24'],
        ]);
        DB::table('member_verification_settings')->where('id', 1)->update(['mode' => 'cloudflare']);
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'action' => 'register'])]);
        $body = ['phone' => '11912345678', 'password' => 'test-password', 'turnstile_token' => 'first'];
        $headers = ['CF-Connecting-IP' => '8.8.8.8', 'CF-IPCountry' => 'BR'];
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson('/api/v1/auth/register', $body, $headers)->assertCreated();
        $this->assertDatabaseHas('members', ['phone' => $body['phone'], 'registration_ip' => '192.0.2.1', 'registration_country' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.12'])
            ->postJson('/api/v1/auth/register', [...$body, 'phone' => '11912345679', 'turnstile_token' => 'second'], $headers)
            ->assertCreated();
        $this->assertDatabaseHas('members', ['phone' => '11912345679', 'registration_ip' => '8.8.8.8', 'registration_country' => null]);
        $member = Member::query()->where('phone', '11912345679')->sole();
        Queue::assertPushed(ResolveRegistrationCountry::class, 1);
        Queue::assertPushed(ResolveRegistrationCountry::class, fn ($job) => $job->memberId === $member->id && $job->edgeCountry === 'BR');
        (new ResolveRegistrationCountry($member->id, 'BR'))->handle();
        $this->assertSame('BR', $member->refresh()->registration_country);
    }

    public function test_registration_country_lookup_runs_only_in_the_queued_job(): void
    {
        Queue::fake();
        config(['services.turnstile.site_key' => 'public', 'services.turnstile.secret' => 'private']);
        DB::table('member_verification_settings')->where('id', 1)->update(['mode' => 'cloudflare']);
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'action' => 'register']),
            'https://api.country.is/8.8.8.8' => Http::response(['ip' => '8.8.8.8', 'country' => 'US']),
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->postJson('/api/v1/auth/register', [
            'phone' => '11912345678', 'password' => 'test-password', 'turnstile_token' => 'first',
        ])->assertCreated();

        $member = Member::query()->sole();
        $this->assertSame('8.8.8.8', $member->registration_ip);
        $this->assertNull($member->registration_country);
        Http::assertSentCount(1);
        Queue::assertPushed(ResolveRegistrationCountry::class, fn ($job) => $job->memberId === $member->id && $job->edgeCountry === null);

        (new ResolveRegistrationCountry($member->id, null))->handle();
        $this->assertSame('US', $member->refresh()->registration_country);
        Http::assertSentCount(2);
    }

    public function test_self_binding_uses_cloudflare_mode_and_records_each_valid_submission(): void
    {
        config(['services.turnstile.site_key' => 'public', 'services.turnstile.secret' => 'private']);
        DB::table('member_verification_settings')->where('id', 1)->update(['mode' => 'cloudflare']);
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $platform = Platform::create(['name' => 'Active Site', 'promotion_url' => 'https://site.example.test', 'rating' => 5]);
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => ['endpoint' => 'https://query.example.test']])->save();
        $this->mock(PlatformQueryClient::class)->shouldReceive('lookup')->once()->andReturn(new PlatformAccount('PL-11', 200, 300, now()));
        Http::fakeSequence('https://challenges.cloudflare.com/turnstile/v0/siteverify')
            ->push(['success' => true, 'action' => 'register'])->push(['success' => true, 'action' => 'platform_binding']);

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $url = "/api/v1/platforms/{$platform->id}/binding";
        $this->postJson($url.'/code', ['phone' => $member->phone])->assertConflict();
        $this->postJson($url, ['phone' => $member->phone, 'turnstile_token' => 'wrong-action'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson($url, ['phone' => $member->phone, 'turnstile_token' => 'correct-action'])->assertCreated();
        $this->assertDatabaseHas('platform_binding_attempts', ['member_id' => $member->id, 'method' => 'self', 'status' => 'failed']);
        $this->assertDatabaseHas('platform_binding_attempts', [
            'member_id' => $member->id, 'method' => 'self', 'status' => 'succeeded', 'platform_account_id' => $member->phone,
        ]);
        $this->assertDatabaseHas('platform_bindings', ['member_id' => $member->id, 'protection_status' => 'inactive']);
    }

    public function test_recharge_lookup_is_scoped_to_the_selected_members_binding(): void
    {
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '运营', 'permissions' => ['members.view']]));
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $other = Member::create(['phone' => '11912345679', 'password' => 'test-password']);
        $platform = Platform::create(['name' => 'Existing Site', 'promotion_url' => 'https://site.example.test', 'rating' => 5]);
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => 'PL-1024',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0,
            'baseline_valid_bet_cents' => 0, 'baseline_observed_at' => now(),
        ]);
        $service = $this->mock(PlatformRecharges::class);
        $service->shouldReceive('list')->once()->withArgs(fn (Member $owner, string $id, int $page): bool => $owner->is($member) && $id === (string) $binding->id && $page === 1
        )->andReturn([
            'data' => [['reference' => 'payment-1', 'amount_cents' => 3900, 'recharged_at' => now()->toISOString()]],
            'current_page' => 1, 'last_page' => 1, 'has_more' => false,
        ]);
        $service->shouldReceive('savedSummary')->once()
            ->withArgs(fn (PlatformBinding $owner): bool => $owner->is($binding))->andReturn(['count' => 2, 'total_cents' => '5900']);

        $this->actingAs($operator, 'web')->get("/admin/members/{$other->id}/recharges/{$binding->id}")->assertNotFound();
        $this->get("/admin/members/{$member->id}/recharges/{$binding->id}")
            ->assertOk()->assertSee('payment-1')->assertSee('R$ 39.00')
            ->assertSee('已同步充值次数')->assertSee('2 次')->assertSee('R$ 59.00');
    }

    public function test_unhealthy_platform_shows_saved_recharges_instead_of_a_404(): void
    {
        Http::fake();
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '充值记录运营', 'permissions' => ['members.view']]));
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $platform = Platform::create(['name' => 'Existing Site', 'promotion_url' => 'https://site.example.test', 'rating' => 5]);
        $platform->forceFill(['active_query_revision' => 1, 'query_unhealthy_at' => now()])->save();
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => 'PL-1024',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0,
            'baseline_valid_bet_cents' => 0, 'baseline_observed_at' => now(),
        ]);
        $record = ['platform_binding_id' => $binding->id, 'network' => 'panda', 'amount_cents' => 3900];
        DB::table('platform_recharges')->insert([
            $record + ['external_id' => 'saved-recharge', 'recharged_at' => now()->subDay()],
            $record + ['external_id' => 'old-recharge', 'recharged_at' => now()->subMonths(2)],
        ]);

        $this->actingAs($operator, 'web')->get("/admin/members/{$member->id}/recharges/{$binding->id}")
            ->assertOk()->assertSee('本地记录')->assertSee('saved-recharge')->assertSee('R$ 39.00')
            ->assertSee('1 次')->assertDontSee('R$ 78.00')->assertDontSee('old-recharge');
        Http::assertNothingSent();
    }
}
