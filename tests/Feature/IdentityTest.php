<?php

namespace Tests\Feature;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_guest_api_errors_are_json(): void
    {
        $this->get('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->withToken('1|unused')->get('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_verified_registration_login_and_logout(): void
    {
        $code = $this->requestCode('register');
        $credentials = ['phone' => '+55 (11) 91234-5678', 'password' => 'example-pass-123'];

        $this->postJson('/api/v1/auth/register', $credentials + ['code' => $code])
            ->assertCreated()->assertJsonPath('phone', '11912345678');
        $this->postJson('/api/v1/auth/register', $credentials + ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('members', 1);
        $member = Member::query()->sole();
        $this->assertTrue(Hash::check($credentials['password'], $member->password));

        $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $member->id);
        $this->assertNotNull($member->refresh()->last_login_at);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_code_cannot_be_used_for_a_different_purpose(): void
    {
        $code = $this->requestCode('register');
        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => '11912345678', 'code' => $code, 'password' => 'replacement-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_production_cannot_use_the_development_sms_simulator(): void
    {
        $this->withSession(['_token' => 'test-csrf-token']);
        $this->app->instance('env', 'production');
        $this->postJson('/api/v1/auth/sms-code', [
            'phone' => '11912345678', 'purpose' => 'register', '_token' => 'test-csrf-token',
        ])->assertServiceUnavailable();
        $this->assertDatabaseEmpty('sms_challenges');
    }

    public function test_password_reset_revokes_existing_member_sessions(): void
    {
        $password = 'example-pass-123';
        Member::query()->create(['phone' => '11912345678', 'password' => $password]);
        $credentials = ['phone' => '11912345678', 'password' => $password];
        $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $code = $this->requestCode('reset_password');

        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => '11912345678', 'code' => $code, 'password' => 'new-example-pass',
        ])->assertNoContent();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $credentials)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', [...$credentials, 'password' => 'new-example-pass'])->assertOk();
    }

    public function test_disabled_member_cannot_reuse_a_session_after_restoration(): void
    {
        $credentials = ['phone' => '11912345678', 'password' => 'example-pass-123'];
        $member = Member::query()->create($credentials);
        $this->postJson('/api/v1/auth/login', $credentials)->assertOk();

        $member->changeDisabledStatus(true);
        $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'member_disabled');
        $this->postJson('/api/v1/auth/login', $credentials)->assertForbidden();

        $member->changeDisabledStatus(false);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
    }

    public function test_member_and_admin_guards_are_independent(): void
    {
        $credentials = ['phone' => '11912345678', 'password' => 'example-pass-123'];
        Member::query()->create($credentials);
        $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->get('/admin/members')->assertRedirect('/admin/login');

        $admin = User::factory()->create();
        $this->actingAs($admin, 'web')->get('/admin/members')->assertForbidden();
        $admin->forceFill(['panel_enabled' => true])->save();
        $this->get('/admin/members')->assertForbidden();
    }

    public function test_member_management_uses_current_role_permissions_and_audits_changes(): void
    {
        $member = Member::query()->create(['phone' => '11912345678', 'password' => 'example-pass-123']);
        $admin = User::factory()->create();
        $admin->forceFill(['panel_enabled' => true])->save();
        $view = AdminRole::query()->create(['name' => 'Read only', 'permissions' => ['members.view']]);
        $change = AdminRole::query()->create(['name' => 'Change status', 'permissions' => ['members.change-status']]);
        $admin->roles()->attach($view);

        $this->actingAs($admin, 'web')->get('/admin/members')->assertOk();
        $action = TestAction::make('disable')->table($member);
        Livewire::test(ListMembers::class)->assertActionHidden($action);

        $admin->roles()->attach($change);
        Livewire::test(ListMembers::class)->assertActionVisible($action)->callAction($action);
        $this->assertNotNull($member->refresh()->disabled_at);
        $this->assertDatabaseHas('admin_audits', [
            'user_id' => $admin->id, 'member_id' => $member->id, 'action' => 'member.disabled',
        ]);

        $admin->roles()->detach($change);
        $this->get('/admin/members')->assertOk();
        Livewire::test(ListMembers::class)->assertActionHidden(TestAction::make('restore')->table($member));
    }

    private function requestCode(string $purpose): string
    {
        Log::spy();
        $code = null;
        $this->postJson('/api/v1/auth/sms-code', [
            'phone' => '11912345678', 'purpose' => $purpose,
        ])->assertAccepted()->assertJsonMissingPath('code');

        Log::shouldHaveReceived('debug')->once()->withArgs(
            function (string $message, array $context) use (&$code): bool {
                $code = $context['code'] ?? null;

                return $message === 'Local SMS simulation' && is_string($code);
            }
        );

        return $code;
    }
}
