<?php

namespace Tests\Feature;

use App\Auth\CaptchaImage;
use App\Filament\Pages\MemberVerificationSettings;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class MemberVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_switch_registration_to_single_use_image_verification(): void
    {
        $this->getJson('/api/v1/auth/verification')->assertOk()->assertJsonPath('data.mode', 'sms');
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $this->actingAs($operator, 'web')->get('/admin/member-verification-settings')->assertForbidden();
        $operator->roles()->attach(AdminRole::create(['name' => '验证码管理', 'permissions' => ['system.manage-member-verification']]));
        Livewire::test(MemberVerificationSettings::class)->fillForm(['sms_enabled' => false])->call('save')->assertHasNoFormErrors();

        $this->getJson('/api/v1/auth/verification')->assertOk()->assertJsonPath('data.mode', 'image');
        $this->postJson('/api/v1/auth/sms-code', ['phone' => '11912345678', 'purpose' => 'register'])->assertConflict();
        [$challenge, $answer] = $this->challenge('register');
        $body = ['phone' => '11912345678', 'password' => 'test-password', 'code' => $answer, 'challenge_id' => $challenge];
        $this->postJson('/api/v1/auth/register', $body)->assertCreated()->assertJsonPath('phone_verified_at', null);
        $this->postJson('/api/v1/auth/register', [...$body, 'phone' => '11912345679'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseEmpty('sms_challenges');
    }

    public function test_guest_cannot_reset_a_password_with_an_image_but_signed_in_member_can_change_it_with_current_password(): void
    {
        DB::table('member_verification_settings')->where('id', 1)->update(['sms_enabled' => false]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $this->postJson('/api/v1/auth/reset-password', ['phone' => $member->phone, 'password' => 'new-password'])->assertConflict();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        [$challenge, $answer] = $this->challenge('reset_password');
        $body = ['phone' => $member->phone, 'password' => 'new-password', 'challenge_id' => $challenge, 'code' => $answer];
        $this->postJson('/api/v1/auth/reset-password', $body + ['current_password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        [$challenge, $answer] = $this->challenge('reset_password');
        $this->postJson('/api/v1/auth/reset-password', [...$body, 'challenge_id' => $challenge, 'code' => $answer, 'current_password' => 'test-password'])->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['phone' => $member->phone, 'password' => 'new-password'])->assertOk();
    }

    public function test_image_mode_does_not_grant_access_to_platform_accounts_that_require_sms(): void
    {
        DB::table('member_verification_settings')->where('id', 1)->update(['sms_enabled' => false]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $platform = Platform::create(['name' => 'Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.0']);
        $platform->forceFill(['active_query_revision' => 1])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $url = '/api/v1/platforms/'.$platform->id.'/binding';
        $this->postJson($url.'/code', ['phone' => $member->phone])->assertConflict();
        $this->postJson($url, ['phone' => $member->phone, 'code' => '123456'])->assertConflict();
        $this->assertDatabaseEmpty('platform_bindings');
    }

    private function challenge(string $purpose): array
    {
        $answer = null;
        $renderer = Mockery::mock(CaptchaImage::class);
        $renderer->shouldReceive('render')->once()->andReturnUsing(function (string $code) use (&$answer): string {
            $answer = $code;

            return 'data:image/png;base64,test';
        });
        $this->app->instance(CaptchaImage::class, $renderer);
        $response = $this->postJson('/api/v1/auth/captcha', ['purpose' => $purpose])->assertOk()->assertJsonMissingPath('data.code');
        $cookie = $response->getCookie(config('session.cookie'));
        $this->withCredentials()->withCookie($cookie->getName(), $cookie->getValue());

        return [$response->json('data.challenge_id'), $answer];
    }
}
