<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SiteInstallationTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rebates-install-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->sandbox.'/app/private');
        File::copy(base_path('.env.example'), $this->sandbox.'/.env.example');
        $this->app->useEnvironmentPath($this->sandbox);
        $this->app->useStoragePath($this->sandbox);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    public function test_wizard_is_closed_until_enabled_on_the_server(): void
    {
        $this->get('/install')->assertNotFound();
        $this->artisan('install:enable')->assertSuccessful();
        $this->get('/install')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/install', ['token' => 'untrusted'])->assertNotFound();
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_fresh_home_guides_installation_without_an_application_key(): void
    {
        config(['app.key' => null]);

        $this->get('/')->assertServiceUnavailable()->assertSee('php artisan install:enable');
        $this->artisan('install:enable')->assertSuccessful();
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk();
    }

    public function test_an_existing_environment_disables_installation(): void
    {
        $this->artisan('install:enable')->assertSuccessful();
        File::put($this->sandbox.'/.env', "APP_KEY=existing\n");

        $this->get('/install')->assertNotFound();
        $this->artisan('install:enable')->assertFailed();
        $this->assertSame("APP_KEY=existing\n", File::get($this->sandbox.'/.env'));
    }

    public function test_home_reports_a_missing_key_when_environment_already_exists(): void
    {
        config(['app.key' => null]);
        File::put($this->sandbox.'/.env', "APP_KEY=\n");

        $this->get('/')->assertServiceUnavailable()->assertSee('APP_KEY');
        $this->get('/install')->assertNotFound();
    }

    public function test_invalid_form_does_not_echo_passwords_or_the_installation_code(): void
    {
        $token = str_repeat('a', 64);
        File::put($this->sandbox.'/app/private/site-installer.json', json_encode([
            'digest' => hash('sha256', $token), 'expires_at' => time() + 60,
        ]));

        $this->post('/install?token='.$token)->assertNotFound();
        $this->post('/install', ['token' => $token, 'db_password' => 'secret-database-value'])
            ->assertUnprocessable()
            ->assertDontSee($token)
            ->assertDontSee('secret-database-value');
    }

    public function test_expired_installation_codes_close_the_wizard(): void
    {
        $token = str_repeat('a', 64);
        File::put($this->sandbox.'/app/private/site-installer.json', json_encode([
            'digest' => hash('sha256', $token), 'expires_at' => time() - 1,
        ]));

        $this->get('/install')->assertNotFound();
        $this->post('/install', ['token' => $token])->assertNotFound();
    }

    public function test_installation_reports_an_unwritable_public_path_before_using_mysql(): void
    {
        $token = str_repeat('a', 64);
        $this->app->usePublicPath($this->sandbox.'/missing-public');
        File::put($this->sandbox.'/app/private/site-installer.json', json_encode([
            'digest' => hash('sha256', $token), 'expires_at' => time() + 60,
        ]));

        $this->post('/install', [
            'token' => $token,
            'app_url' => 'https://example.test',
            'db_host' => '127.0.0.1',
            'db_port' => 1,
            'db_name' => 'rebates_test',
            'db_user' => 'rebates',
            'db_password' => 'secret-database-value',
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'example-pass-123',
            'password_confirmation' => 'example-pass-123',
        ])->assertStatus(500)
            ->assertSee('后台资源发布')
            ->assertSee('public')
            ->assertDontSee('secret-database-value');

        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }
}
