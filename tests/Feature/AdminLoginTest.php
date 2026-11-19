<?php

namespace Tests\Feature;

use App\Filament\Pages\AdminLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_sign_in_with_a_username_and_five_digit_password(): void
    {
        $this->artisan('admin:create', ['username' => 'operator'])
            ->expectsQuestion('后台密码（最少 5 个字符）', '12345')
            ->assertSuccessful();

        $this->get('/admin/login')->assertOk()->assertSee('账号')->assertDontSee('data.email');
        Livewire::test(AdminLogin::class)
            ->fillForm(['username' => 'OPERATOR', 'password' => '12345'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated('web');
        $this->assertDatabaseHas('users', ['username' => 'operator', 'email' => null]);
    }
}
