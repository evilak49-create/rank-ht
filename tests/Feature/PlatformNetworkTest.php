<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\AdminRole;
use App\Models\Platform;
use App\Models\PointRule;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_featured_position_is_reported_before_leaving_platform_details(): void
    {
        $operator = $this->operator(['platforms.view', 'platforms.edit', 'platforms.configure-query']);
        $this->actingAs($operator, 'web');
        app(PlatformAdministration::class)->create([
            'name' => 'Featured Platform', 'promotion_url' => 'https://example.test/featured', 'featured_position' => 2,
        ], $operator);

        Livewire::test(ListPlatforms::class)->mountAction('create_platform')
            ->goToWizardStep(2)
            ->set('mountedActions.0.data.name', 'New Platform')
            ->set('mountedActions.0.data.promotion_url', 'https://example.test/new')
            ->set('mountedActions.0.data.featured_position', 2)
            ->goToWizardStep(3)
            ->assertHasActionErrors(['featured_position' => 'unique'])
            ->assertNotified('首页精选位置不可用');

        $this->assertDatabaseCount('platforms', 1);
    }

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
            ->assertActionDataSet(['network' => 'panda', 'rating' => 5])
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
            'guarantee_amount_brl' => '1000000.50', 'max_rebate_percent' => '4.125',
            'endpoint' => 'https://partner.example.test/api/backend/trpc/user.list',
            'admin_host' => 'admin.example.test', 'token' => 'test-only-token', 'account' => 'fixture',
            'fingerprint' => 'test-fingerprint', 'tenant_id' => 123, 'region_id' => 1,
            'client_language' => 'zh-CN', 'test_phone' => '推广 Test+_@01',
        ];
        Livewire::test(ListPlatforms::class)->callAction('create_platform', [
            ...$draft, 'endpoint' => 'https://denied.example.test/api/backend/trpc/user.list',
        ])->assertHasActionErrors(['endpoint']);
        $this->assertDatabaseEmpty('platforms');
        Livewire::test(ListPlatforms::class)->callAction('create_platform', $draft)
            ->assertHasNoActionErrors()->assertNotified('平台已保存');

        $platform = Platform::query()->sole();
        $this->assertDatabaseHas('platforms', ['id' => $platform->id, 'network' => 'panda', 'query_revision' => 1, 'active_query_revision' => null]);
        $this->assertSame('test-only-token', $platform->draft_query['token']);
        $this->assertSame('推广 Test+_@01', $platform->draft_query['test_phone']);
        $this->assertStringNotContainsString('test-only-token', DB::table('platforms')->sole()->draft_query);
        $this->getJson('/api/v1/platforms')->assertOk()->assertJsonCount(0, 'data');

        $platform->forceFill(['active_query_revision' => 1])->save();
        $this->getJson('/api/v1/platforms/'.$platform->id)->assertOk()
            ->assertJsonPath('data.guarantee_amount_brl', '1000000.50')
            ->assertJsonPath('data.max_rebate_percent', '4.125000');

        $edit = TestAction::make('edit_profile')->table($platform);
        Livewire::test(ListPlatforms::class)->mountAction($edit)
            ->assertActionDataSet(['guarantee_amount_brl' => '1000000.50', 'max_rebate_percent' => '4.125000']);
        Livewire::test(ListPlatforms::class)->callAction($edit, ['max_rebate_percent' => '100.001'])
            ->assertHasActionErrors(['max_rebate_percent']);
        Livewire::test(ListPlatforms::class)->callAction($edit, ['guarantee_amount_brl' => null, 'max_rebate_percent' => '8'])
            ->assertHasNoActionErrors();
        $this->getJson('/api/v1/platforms')->assertOk()
            ->assertJsonPath('data.0.guarantee_amount_brl', null)
            ->assertJsonPath('data.0.max_rebate_percent', '8.000000');

        $platform->forceFill(['featured_position' => 2])->save();
        Livewire::test(ListPlatforms::class)->callAction('create_platform', [
            ...$draft, 'name' => 'Second Platform', 'featured_position' => 2,
        ])->assertHasActionErrors(['featured_position' => 'unique'])
            ->assertNotified('首页精选位置不可用');
        $this->assertDatabaseCount('platforms', 1);
    }

    public function test_public_platform_rule_reflects_the_latest_points_and_rebate_configuration(): void
    {
        $platform = app(PlatformAdministration::class)->create([
            'name' => 'Rules Platform', 'promotion_url' => 'https://example.test/register',
        ], $this->operator(['platforms.edit']));
        $this->assertSame('5.0', $platform->rating);
        $url = '/api/v1/platforms/'.$platform->id.'/point-rule';
        $this->getJson($url)->assertNotFound();
        $platform->forceFill(['active_query_revision' => 1])->save();

        PointRule::create([
            'points_per_brl_micros' => 12500, 'wager_multiplier_micros' => 2250000,
            'rebate_percent_micros' => 3250000, 'rebate_wager_multiplier_micros' => 4500000,
        ]);
        $this->getJson($url)->assertOk()->assertExactJson(['data' => [
            'points_per_brl' => '0.012500', 'wager_multiplier' => '2.250000',
            'rebate_percent' => '3.250000', 'rebate_wager_multiplier' => '4.500000',
        ]]);

        PointRule::create([
            'points_per_brl_micros' => 25000, 'wager_multiplier_micros' => 1000000,
            'rebate_percent_micros' => 0, 'rebate_wager_multiplier_micros' => 5000000,
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.points_per_brl', '0.025000')
            ->assertJsonPath('data.rebate_percent', '0.000000')->assertJsonPath('data.rebate_wager_multiplier', null);
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
