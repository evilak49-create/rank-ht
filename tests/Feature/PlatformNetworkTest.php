<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\AdminRole;
use App\Models\Platform;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_platform_defaults_to_panda_and_requires_its_query_configuration(): void
    {
        config([
            'services.platform_query.allowed_hosts' => ['partner.example.test'],
            'services.platform_query.defaults.token' => 'panda-test-default',
        ]);
        $operator = $this->operator(['platforms.view', 'platforms.edit', 'platforms.configure-query']);
        $this->actingAs($operator, 'web');
        $profile = ['name' => 'Network Platform', 'promotion_url' => 'https://example.test/register', 'rating' => '4.8'];
        Livewire::test(ListPlatforms::class)->mountAction('create_platform')
            ->assertActionDataSet(['network' => 'panda'])
            ->assertActionDataSet(['endpoint' => config('services.platform_query.defaults.endpoint'), 'token' => 'panda-test-default'])
            ->assertDontSee('同时保存接口草稿')
            ->set('mountedActions.0.data.network', 'wg')
            ->assertActionDataSet(['endpoint' => null, 'token' => null]);
        Livewire::test(ListPlatforms::class)->callAction('create_platform', $profile + ['token' => null])
            ->assertHasActionErrors(['token']);
        $this->assertDatabaseEmpty('platforms');
        Livewire::test(ListPlatforms::class)->callAction('create_platform', $profile + ['network' => 'unregistered'])
            ->assertHasActionErrors(['network']);
        $draft = $profile + [
            'network' => 'panda',
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'test-only-token', 'account' => 'fixture',
            'fingerprint' => 'test-fingerprint', 'tenant_id' => 123, 'region_id' => 1,
            'client_language' => 'zh-CN', 'test_phone' => '推广 Test+_@01',
        ];
        Livewire::test(ListPlatforms::class)->callAction('create_platform', [
            ...$draft, 'endpoint' => 'https://denied.example.test/api/backend/trpc/user.list',
        ])->assertHasActionErrors(['endpoint']);
        $this->assertDatabaseEmpty('platforms');
        Livewire::test(ListPlatforms::class)->callAction('create_platform', $draft)->assertHasNoActionErrors();

        $platform = Platform::query()->sole();
        $this->assertDatabaseHas('platforms', ['id' => $platform->id, 'network' => 'panda', 'query_revision' => 1, 'active_query_revision' => null]);
        $this->assertSame('test-only-token', $platform->draft_query['token']);
        $this->assertSame('推广 Test+_@01', $platform->draft_query['test_phone']);
        $this->assertStringNotContainsString('test-only-token', DB::table('platforms')->sole()->draft_query);
        $this->getJson('/api/v1/platforms')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_profile_editor_cannot_change_a_platform_network_or_save_credentials_without_permission(): void
    {
        $operator = $this->operator(['platforms.view', 'platforms.edit']);
        $this->actingAs($operator, 'web');
        Livewire::test(ListPlatforms::class)->assertActionHidden('create_platform');
        $service = app(PlatformAdministration::class);
        $profile = ['name' => 'Existing Platform', 'promotion_url' => 'https://example.test/register', 'rating' => '4.8'];
        $platform = $service->create($profile, $operator);
        $service->update($platform, [...$profile, 'network' => 'unregistered'], $operator);
        $this->assertDatabaseHas('platforms', ['id' => $platform->id, 'network' => 'panda', 'query_revision' => 0]);

        try {
            $service->create($profile, $operator, ['token' => 'test-only-token']);
            $this->fail('Saving a query draft requires the query configuration permission.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('platforms', 1);
    }

    private function operator(array $permissions): User
    {
        $user = User::factory()->create();
        $user->forceFill(['panel_enabled' => true])->save();
        $user->roles()->attach(AdminRole::create(['name' => '平台运营测试', 'permissions' => $permissions]));

        return $user;
    }
}
