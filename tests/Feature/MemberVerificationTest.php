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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_admin_can_stage_encrypted_turnstile_credentials_and_enable_them_later(): void
    {
        config(['services.turnstile' => []]);
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '验证码管理', 'permissions' => ['system.manage-member-verification']]));
        $this->actingAs($operator, 'web');
        $settings = Livewire::test(MemberVerificationSettings::class);
        $settings->fillForm(['mode' => 'cloudflare'])->call('save')->assertHasFormErrors(['mode']);
        $settings->fillForm([
            'mode' => 'image', 'turnstile_site_key' => 'widget-public',
            'turnstile_secret' => 'private-widget-secret', 'turnstile_hostname' => 'rank.example.test',
        ])->call('save')->assertHasNoFormErrors()->assertSet('data.turnstile_secret', null);

        $row = DB::table('member_verification_settings')->where('id', 1)->first();
        $this->assertNotSame('private-widget-secret', $row->turnstile_secret);
        $this->assertSame('private-widget-secret', Crypt::decryptString($row->turnstile_secret));
        $this->getJson('/api/v1/auth/verification')->assertOk()->assertJsonPath('data.turnstile_site_key', null);
        $settings = Livewire::test(MemberVerificationSettings::class)->assertSet('data.turnstile_secret', null);
        $settings->fillForm(['mode' => 'cloudflare', 'turnstile_site_key' => 'changed-widget'])
            ->call('save')->assertHasFormErrors(['turnstile_secret']);
        $settings->fillForm(['mode' => 'cloudflare', 'turnstile_site_key' => 'widget-public'])
            ->call('save')->assertHasNoFormErrors();
        $this->getJson('/api/v1/auth/verification')->assertOk()
            ->assertJsonPath('data.turnstile_site_key', 'widget-public')->assertDontSee('private-widget-secret');
        $siteverify = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        Http::fake([$siteverify => Http::response(['success' => true, 'hostname' => 'rank.example.test', 'action' => 'register'])]);
        $this->postJson('/api/v1/auth/register', [
            'phone' => '11912345678', 'password' => 'Example119', 'turnstile_token' => 'fresh-widget-token',
        ])->assertCreated();
        Http::assertSent(fn ($request): bool => $request->url() === $siteverify && $request['secret'] === 'private-widget-secret');
    }

    public function test_cloudflare_protects_signed_in_password_changes_without_replacing_guest_sms(): void
    {
        config([
            'services.turnstile.site_key' => 'turnstile-public',
            'services.turnstile.secret' => 'turnstile-test-secret',
            'services.turnstile.hostname' => 'rank.example.test',
        ]);
        DB::table('member_verification_settings')->where('id', 1)->update(['mode' => 'cloudflare', 'sms_enabled' => false]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'Original19']);
        $url = '/api/v1/auth/reset-password';
        $body = ['phone' => $member->phone, 'password' => 'Replaced39', 'current_password' => 'Original19'];

        $this->getJson('/api/v1/auth/verification')->assertOk()->assertJsonPath('data.reset_mode', 'sms');
        $this->postJson($url, $body + ['turnstile_token' => 'guest'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson('/api/v1/auth/verification')->assertOk()
            ->assertJsonPath('data.reset_mode', 'cloudflare')->assertJsonPath('data.turnstile_site_key', 'turnstile-public');
        $this->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $response = ['success' => true, 'hostname' => 'rank.example.test', 'action' => 'reset_password'];
        Http::fakeSequence('https://challenges.cloudflare.com/turnstile/v0/siteverify')
            ->push([...$response, 'action' => 'register'])
            ->push([...$response, 'hostname' => 'wrong.example.test'])
            ->push($response)->push($response)->push($response);

        $this->postJson($url, $body + ['turnstile_token' => 'wrong-action'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson($url, $body + ['turnstile_token' => 'wrong-host'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson($url, [...$body, 'current_password' => 'Wrong19', 'turnstile_token' => 'used-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson($url, $body + ['turnstile_token' => 'used-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->travel(61)->seconds();
        $this->postJson($url, $body + ['turnstile_token' => 'fresh-token'])->assertNoContent();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['phone' => $member->phone, 'password' => 'Replaced39'])->assertOk();
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
