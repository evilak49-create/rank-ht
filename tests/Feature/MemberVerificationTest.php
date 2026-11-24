<?php

namespace Tests\Feature;

use App\Auth\CaptchaImage;
use App\Filament\Pages\MemberVerificationSettings;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\User;
use App\Platforms\PlatformAccount;
use App\Platforms\PlatformQueryClient;
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
        Livewire::test(MemberVerificationSettings::class)->fillForm(['mode' => 'image'])->call('save')->assertHasNoFormErrors();

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
        $this->postJson('/api/v1/auth/reset-password', ['phone' => $member->phone, 'password' => 'new-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
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

    public function test_each_image_expires_independently_and_submitting_it_does_not_consume_another(): void
    {
        DB::table('member_verification_settings')->where('id', 1)->update(['sms_enabled' => false]);
        [$old, $oldAnswer] = $this->challenge('register');
        $this->travel(2)->minutes();
        [$fresh, $freshAnswer] = $this->challenge('register');
        $this->travel(1)->minutes();
        $data = ['phone' => '11912345678', 'password' => 'test-password'];

        $this->postJson('/api/v1/auth/register', $data + ['challenge_id' => $old, 'code' => $oldAnswer])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/register', $data + ['challenge_id' => $fresh, 'code' => $freshAnswer])
            ->assertCreated();
    }

    public function test_image_mode_can_bind_without_sms_and_does_not_mark_the_phone_as_verified(): void
    {
        DB::table('member_verification_settings')->where('id', 1)->update(['sms_enabled' => false]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'test-password']);
        $platform = Platform::create(['name' => 'Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.0']);
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => ['endpoint' => 'https://example.test']])->save();
        $client = Mockery::mock(PlatformQueryClient::class);
        $client->shouldReceive('lookup')->once()->andReturn(new PlatformAccount('123456', 100, 250, now()));
        $this->app->instance(PlatformQueryClient::class, $client);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $url = '/api/v1/platforms/'.$platform->id.'/binding';
        $this->postJson($url.'/code', ['phone' => $member->phone])->assertConflict();
        $this->postJson($url, ['phone' => $member->phone])->assertUnprocessable()->assertJsonValidationErrors('challenge_id');
        [$challenge, $answer] = $this->challenge('platform_binding');
        $this->assertSame(4, strlen($answer));
        [$anotherChallenge] = $this->challenge('platform_binding');
        $this->assertNotSame($challenge, $anotherChallenge);
        $this->postJson($url, [
            'phone' => $member->phone, 'challenge_id' => $challenge, 'code' => $answer.'A',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson($url, [
            'phone' => $member->phone, 'challenge_id' => $challenge, 'code' => strtolower($answer),
        ])->assertCreated()->assertJsonPath('data.platform_account_id', '123456');
        $this->postJson($url, [
            'phone' => $member->phone, 'challenge_id' => $challenge, 'code' => $answer,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('platform_bindings', ['member_id' => $member->id, 'query_phone' => $member->phone]);
        $this->assertNull($member->refresh()->phone_verified_at);
        $this->assertDatabaseEmpty('sms_challenges');
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
