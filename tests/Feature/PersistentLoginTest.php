<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PersistentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_resume_and_a_new_login_invalidates_the_previous_browser(): void
    {
        $credentials = ['phone' => '11912345678', 'password' => 'member-password'];
        $member = Member::create($credentials);
        $name = Auth::guard('member')->getRecallerName();
        $first = $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $old = $this->browserCookies($first, $name);
        $this->assertGreaterThan(now()->addMonths(6)->timestamp, $first->getCookie($name, false)->getExpiresTime());
        $this->assertTrue($first->getCookie($name, false)->isHttpOnly());

        $this->travel(7)->days();
        $this->browser([$name => $old[$name]]);
        $reopened = $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $member->id);
        $old = $this->browserCookies($reopened, $name);

        $this->browser();
        $current = $this->browserCookies($this->postJson('/api/v1/auth/login', $credentials)->assertOk(), $name);
        $this->browser($old);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->browser([$name => $old[$name]]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->browser($current);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->browser([$name => $current[$name]]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_admin_login_is_persistent_and_previous_browser_is_signed_out(): void
    {
        $user = User::factory()->create(['panel_enabled' => true]);
        Route::middleware('web')->post('/__test/admin-login', function () use ($user) {
            Auth::guard('web')->login($user->fresh());

            return response()->noContent();
        });
        $name = Auth::guard('web')->getRecallerName();
        $first = $this->browserCookies($this->post('/__test/admin-login')->assertNoContent(), $name);
        $this->travel(7)->days();
        $this->browser([$name => $first[$name]]);
        $reopened = $this->get('/admin')->assertOk();
        $first = $this->browserCookies($reopened, $name);

        $this->browser();
        $current = $this->browserCookies($this->post('/__test/admin-login')->assertNoContent(), $name);
        $this->browser($first);
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->browser([$name => $current[$name]]);
        $this->get('/admin')->assertOk();
    }

    private function browserCookies(TestResponse $response, string $name): array
    {
        $session = config('session.cookie');

        return [$name => $response->getCookie($name, false)->getValue(), $session => $response->getCookie($session, false)->getValue()];
    }

    private function browser(array $cookies = []): void
    {
        Auth::forgetGuards();
        $this->app['session.store']->flush();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withUnencryptedCookies($cookies);
    }
}
