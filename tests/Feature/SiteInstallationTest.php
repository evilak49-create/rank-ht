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
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    public function test_wizard_requires_an_explicitly_allowed_visitor_ip(): void
    {
        $this->get('/install')->assertNotFound();
        $this->enableWizard();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/install', ['X-Forwarded-For' => '127.0.0.1'])
            ->assertNotFound();
        $this->post('/install', ['username' => 'operator', 'password' => 'abc123'])->assertNotFound();
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_wizard_requests_only_username_and_password_and_requires_its_browser_cookie(): void
    {
        $this->enableWizard();
        $csrf = $this->loadForm();
        $this->withUnencryptedCookie('site_install_csrf', str_repeat('a', 64))
            ->post('/install', ['_token' => $csrf, 'username' => 'operator', 'password' => 'abc123'])
            ->assertStatus(419);
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_fresh_home_guides_installation_without_an_application_key(): void
    {
        config(['app.key' => null]);

        $this->get('/')->assertServiceUnavailable()->assertSee('php artisan install:enable');
        $this->enableWizard();
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk();
    }

    public function test_an_existing_environment_disables_installation(): void
    {
        $this->enableWizard();
        File::put($this->sandbox.'/.env', "APP_KEY=existing\n");

        $this->get('/install')->assertNotFound();
        $this->artisan('install:enable', ['--ip' => '127.0.0.1'])->assertFailed();
        $this->assertSame("APP_KEY=existing\n", File::get($this->sandbox.'/.env'));
    }

    public function test_home_reports_a_missing_key_when_environment_already_exists(): void
    {
        config(['app.key' => null]);
        File::put($this->sandbox.'/.env', "APP_KEY=\n");

        $this->get('/')->assertServiceUnavailable()->assertSee('APP_KEY');
        $this->get('/install')->assertNotFound();
    }

    public function test_five_character_password_is_rejected_without_echoing_it(): void
    {
        $this->enableWizard();
        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => $csrf, 'username' => 'operator', 'password' => 'p4555'])
            ->assertUnprocessable()
            ->assertSee('管理员密码至少 6 个字符')
            ->assertDontSee('p4555');
    }

    public function test_expired_allowlist_closes_the_wizard(): void
    {
        $this->enableWizard();
        $csrf = $this->loadForm();
        $this->travel(31)->minutes();

        $this->get('/install')->assertNotFound();
        $this->post('/install', ['_token' => $csrf])->assertNotFound();
    }

    public function test_installation_reports_an_unwritable_public_path_before_using_mysql(): void
    {
        $this->enableWizard();
        $this->app->usePublicPath($this->sandbox.'/missing-public');

        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => $csrf, 'username' => 'operator', 'password' => 'abc123'])
            ->assertStatus(500)
            ->assertSee('后台资源发布')
            ->assertSee('public')
            ->assertDontSee('secret-database-value');
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    private function enableWizard(): void
    {
        $this->artisan('install:enable', ['--ip' => '127.0.0.1'])
            ->expectsQuestion('网站完整地址（例如 https://example.com）', 'https://example.test')
            ->expectsQuestion('MySQL 主机', '127.0.0.1')
            ->expectsQuestion('MySQL 端口', '1')
            ->expectsQuestion('MySQL 数据库名称', 'rebates_test')
            ->expectsQuestion('MySQL 用户名', 'rebates')
            ->expectsQuestion('MySQL 密码（可留空）', 'secret-database-value')
            ->assertSuccessful();
    }

    private function loadForm(): string
    {
        $page = $this->get('/install')
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('name="username"', escape: false)
            ->assertSee('name="password"', escape: false)
            ->assertDontSee('name="email"', escape: false)
            ->assertDontSee('name="db_password"', escape: false)
            ->assertDontSee('一次性安装码')
            ->assertDontSee('secret-database-value');

        $cookie = $page->getCookie('site_install_csrf', decrypt: false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('site_install_csrf', $cookie->getValue());
        $this->assertSame(1, preg_match('/name="_token" value="([a-f0-9]{64})"/', $page->getContent(), $matches));

        return $matches[1];
    }
}
