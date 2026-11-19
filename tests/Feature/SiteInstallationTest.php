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
        $this->app->useEnvironmentPath($this->sandbox);
        $this->app->useStoragePath($this->sandbox);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    public function test_opening_the_regular_install_url_shows_the_form_after_server_setup(): void
    {
        $this->enableWizard();

        $this->get('/install')->assertOk()->assertSee('MySQL 数据库');
        $this->get('/install/activate')->assertNotFound();
    }

    public function test_unapproved_database_credentials_are_rejected_before_connecting(): void
    {
        $this->enableWizard();
        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => $csrf, 'db_host' => 'another-database.test'] + $this->formValues())
            ->assertUnprocessable()
            ->assertSee('MySQL 连接信息与服务器授权不一致')
            ->assertDontSee('secret-database-value');

        $saved = File::get($this->sandbox.'/app/private/site-installer.json');
        $this->assertStringNotContainsString('secret-database-value', $saved);
        $this->assertSame(1, json_decode($saved, true)['failed_attempts']);
    }

    public function test_install_form_is_closed_before_server_setup(): void
    {
        $this->get('/install')->assertNotFound();
        $this->post('/install', ['username' => 'operator', 'password' => 'abc123'])->assertNotFound();
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_wizard_shows_database_options_and_requires_browser_authorization(): void
    {
        $this->enableWizard();
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
        $this->enableWizard();
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
        $this->enableWizard();
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
        $this->enableWizard();
        $csrf = $this->loadForm();
        $this->post('/install', ['_token' => $csrf, 'password' => 'p455'] + $this->formValues())
            ->assertUnprocessable()
            ->assertSee('管理员密码至少 5 个字符')
            ->assertDontSee('p455')
            ->assertDontSee('secret-database-value');
    }

    public function test_an_expired_install_window_cannot_open_or_submit(): void
    {
        $this->enableWizard();
        $csrf = $this->loadForm();
        $this->travel(31)->minutes();

        $this->get('/install')->assertNotFound();
        $this->post('/install', ['_token' => $csrf])->assertNotFound();
    }

    public function test_too_many_incorrect_database_attempts_close_installation(): void
    {
        $this->enableWizard();
        $csrf = $this->loadForm();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/install', ['_token' => $csrf, 'db_password' => 'incorrect'] + $this->formValues())
                ->assertUnprocessable();
        }

        $this->get('/install')->assertNotFound();
        $this->post('/install', ['_token' => $csrf] + $this->formValues())->assertNotFound();
    }

    public function test_installation_reports_an_unwritable_public_path_before_using_mysql(): void
    {
        $this->enableWizard();
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

    public function test_enable_command_authorizes_the_database_even_without_a_template(): void
    {
        $this->app->setBasePath($this->sandbox.'/release');

        $this->artisan('install:enable', ['--url' => 'https://example.test'])
            ->expectsQuestion('MySQL 主机', '127.0.0.1')
            ->expectsQuestion('MySQL 端口', '1')
            ->expectsQuestion('MySQL 数据库名称', 'rebates_test')
            ->expectsQuestion('MySQL 用户名', 'rebates')
            ->expectsQuestion('MySQL 密码', 'secret-database-value')
            ->expectsOutput('安装已开启 30 分钟。请打开 https://example.test/install，填写已授权的 MySQL 连接信息和后台账号。')
            ->assertSuccessful();
    }

    public function test_enable_command_reports_the_failed_storage_check(): void
    {
        $this->app->useStoragePath($this->sandbox.'/missing-storage');

        $this->artisan('install:enable', ['--url' => 'https://example.test'])
            ->expectsOutputToContain('storage/app/private 不存在或当前用户无法写入')
            ->assertFailed();
    }

    private function enableWizard(): void
    {
        $this->assertTrue(app(InstallerAccess::class)->enable('https://example.test', $this->formValues()));
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

        $cookie = $page->getCookie('site_install_browser', decrypt: false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('site_install_browser', $cookie->getValue());
        $this->assertSame(1, preg_match('/name="_token" value="([a-f0-9]{64})"/', $page->getContent(), $matches));

        return $matches[1];
    }
}
