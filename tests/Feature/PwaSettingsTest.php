<?php

namespace Tests\Feature;

use App\Filament\Pages\PwaSettingsPage;
use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class PwaSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_grants_pwa_access_to_the_existing_default_admin_role(): void
    {
        $role = AdminRole::create(['name' => '会员运营', 'permissions' => ['system.manage-roles', 'system.manage-admins']]);
        $limited = AdminRole::create(['name' => '普通运营', 'permissions' => ['members.view']]);
        $migration = require database_path('migrations/2026_11_27_000004_create_pwa_settings.php');
        $migration->down();
        $migration->up();
        $this->assertContains('system.manage-pwa', $role->fresh()->permissions);
        $this->assertNotContains('system.manage-pwa', $limited->fresh()->permissions);
    }

    public function test_public_configuration_and_installation_resources_work_without_a_member_session(): void
    {
        $this->getJson('/api/v1/pwa/config')->assertOk()
            ->assertJsonPath('host', 'qvip.com')
            ->assertJsonPath('copy.zh-CN.message', '下载APP，马上提现你的返利')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/pwa/manifest.webmanifest')->assertOk()
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.2.purpose', 'maskable');
        $this->get('/api/v1/pwa/worker.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/');
        $icon = $this->get('/api/v1/pwa/icons/apple.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([180, 180], array_slice(getimagesizefromstring($icon->getContent()), 0, 2));
    }

    public function test_only_authorized_operators_can_change_pwa_settings_and_upload_an_icon(): void
    {
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '后台访问', 'permissions' => ['system.manage-roles']]));
        $this->actingAs($operator, 'web')->get('/admin/pwa-settings')->assertForbidden();
        $operator->roles()->attach(AdminRole::create(['name' => 'PWA 管理', 'permissions' => ['system.manage-pwa']]));
        $this->get('/admin/pwa-settings')->assertOk();

        $form = Livewire::test(PwaSettingsPage::class)
            ->fillForm(['host' => 'unlisted.example'])
            ->call('save')->assertHasFormErrors(['host']);
        $form->fillForm([
            'enabled' => false,
            'host' => 'www.qvip.com',
            'name' => 'QVIP Test',
            'start_path' => '/platforms',
            'message_zh' => '自定义安装提示',
            'button_pt' => 'Instalar agora',
            'offline_zh' => '<script>alert(1)</script>',
            'icon_upload' => UploadedFile::fake()->image('icon.png', 512, 512),
        ])->call('save')->assertHasNoFormErrors();

        $this->getJson('/api/v1/pwa/config')->assertOk()
            ->assertJsonPath('enabled', false)->assertJsonPath('host', 'www.qvip.com')
            ->assertJsonPath('copy.zh-CN.message', '自定义安装提示')
            ->assertJsonPath('copy.pt-BR.button', 'Instalar agora')
            ->assertJsonMissingPath('icon_upload');
        $this->get('/api/v1/pwa/manifest.webmanifest')->assertOk()
            ->assertJsonPath('name', 'QVIP Test')->assertJsonPath('start_url', '/#/platforms');
        $icon = $this->get('/api/v1/pwa/icons/512.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([512, 512], array_slice(getimagesizefromstring($icon->getContent()), 0, 2));
        $this->get('/api/v1/pwa/offline')->assertOk()
            ->assertHeader('X-QVIP-Offline', '1')
            ->assertSee('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
