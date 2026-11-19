<?php

namespace Tests\Feature;

use App\Installation\InstallerAccess;
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

    public function test_install_link_authorizes_one_browser_without_requiring_an_ip(): void
    {
        $link = app(InstallerAccess::class)->enable('https://example.test');
        $this->assertIsString($link);
        $this->get('/install')->assertNotFound();
        $this->get('/install/activate')->assertOk()->assertDontSee($link);

        $response = $this->post('/install/activate', ['link' => $link])->assertNoContent();
        $cookie = $response->getCookie('site_install_browser', decrypt: false);
        $this->assertNotNull($cookie);
        $this->post('/install/activate', ['link' => $link])->assertNotFound();
        $this->withUnencryptedCookie('site_install_browser', $cookie->getValue())
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.55'])
            ->get('/install')->assertOk()->assertSee('账号');
    }

    public function test_public_visitors_cannot_open_or_submit_the_install_form(): void
    {
        $this->get('/install')->assertNotFound();
        $link = $this->enableWizard();
        $this->get('/install')->assertNotFound();
        $this->post('/install/activate?link='.$link)->assertNotFound();
        $this->post('/install/activate', ['link' => str_repeat('a', 64)])->assertNotFound();
        $this->post('/install', ['username' => 'operator', 'password' => 'abc123'])->assertNotFound();
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_wizard_shows_database_options_and_requires_browser_authorization(): void
    {
        $this->claimWizard();
        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => str_repeat('a', 64), 'username' => 'operator', 'password' => 'abc123'])
            ->assertStatus(419);
        $this->assertSame(64, strlen($csrf));
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_fresh_home_guides_installation_without_an_application_key(): void
    {
        config(['app.key' => null]);

        $this->get('/')->assertServiceUnavailable()->assertSee('php artisan install:enable');
        $this->claimWizard();
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk();
    }

    public function test_an_existing_environment_disables_installation(): void
    {
        $this->enableWizard();
        File::put($this->sandbox.'/.env', "APP_KEY=existing\n");

        $this->get('/install')->assertNotFound();
        $this->artisan('install:enable')->assertFailed();
        $this->assertSame("APP_KEY=existing\n", File::get($this->sandbox.'/.env'));
    }

    public function test_the_saved_private_environment_closes_the_installer(): void
    {
        $this->claimWizard();
        File::put($this->sandbox.'/app/private/site.env', "APP_KEY=existing\n");

        $this->get('/install')->assertNotFound();
        $this->artisan('install:enable')->assertFailed();
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_home_reports_a_missing_key_when_environment_already_exists(): void
    {
        config(['app.key' => null]);
        File::put($this->sandbox.'/.env', "APP_KEY=\n");

        $this->get('/')->assertServiceUnavailable()->assertSee('APP_KEY');
        $this->get('/install')->assertNotFound();
    }

    public function test_four_character_password_is_rejected_without_echoing_it(): void
    {
        $this->claimWizard();
        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => $csrf, 'password' => 'p455'] + $this->formValues())
            ->assertUnprocessable()
            ->assertSee('管理员密码至少 5 个字符')
            ->assertDontSee('p455')
            ->assertDontSee('secret-database-value');
    }

    public function test_an_expired_install_link_and_browser_session_are_rejected(): void
    {
        $unused = $this->enableWizard();
        $this->travel(31)->minutes();
        $this->post('/install/activate', ['link' => $unused])->assertNotFound();

        $this->claimWizard();
        $csrf = $this->loadForm();
        $this->travel(31)->minutes();

        $this->get('/install')->assertNotFound();
        $this->post('/install', ['_token' => $csrf])->assertNotFound();
    }

    public function test_installation_reports_an_unwritable_public_path_before_using_mysql(): void
    {
        $this->claimWizard();
        $this->app->useEnvironmentPath($this->sandbox.'/missing-root-environment-directory');
        $this->app->usePublicPath($this->sandbox.'/missing-public');

        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => $csrf] + $this->formValues())
            ->assertStatus(500)
            ->assertSee('后台资源发布')
            ->assertSee('public')
            ->assertDontSee('secret-database-value');
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_enable_command_only_requires_the_site_url(): void
    {
        $this->artisan('install:enable', ['--url' => 'https://example.test'])
            ->expectsOutput('请在 30 分钟内用浏览器打开以下链接，填写 MySQL 配置和后台账号。')
            ->assertSuccessful();
    }

    public function test_enable_command_reports_the_failed_storage_check(): void
    {
        $this->app->useStoragePath($this->sandbox.'/missing-storage');

        $this->artisan('install:enable', ['--url' => 'https://example.test'])
            ->expectsOutputToContain('storage/app/private 不存在或当前用户无法写入')
            ->assertFailed();
    }

    private function enableWizard(): string
    {
        return app(InstallerAccess::class)->enable('https://example.test');
    }

    private function formValues(): array
    {
        return [
            'app_url' => 'https://example.test',
            'db_host' => '127.0.0.1',
            'db_port' => '1',
            'db_name' => 'rebates_test',
            'db_user' => 'rebates',
            'db_password' => 'secret-database-value',
            'username' => 'operator',
            'password' => '12345',
        ];
    }

    private function claimWizard(): void
    {
        $response = $this->post('/install/activate', ['link' => $this->enableWizard()])->assertNoContent();
        $cookie = $response->getCookie('site_install_browser', decrypt: false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('site_install_browser', $cookie->getValue());
    }

    private function loadForm(): string
    {
        $page = $this->get('/install')
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('name="username"', escape: false)
            ->assertSee('name="password"', escape: false)
            ->assertSee('name="app_url"', escape: false)
            ->assertSee('name="db_password"', escape: false)
            ->assertDontSee('name="email"', escape: false)
            ->assertDontSee('name="password_confirmation"', escape: false)
            ->assertDontSee('一次性安装码')
            ->assertDontSee('secret-database-value');

        $this->assertSame(1, preg_match('/name="_token" value="([a-f0-9]{64})"/', $page->getContent(), $matches));

        return $matches[1];
    }
}
