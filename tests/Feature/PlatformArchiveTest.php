<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\AdminRole;
use App\Models\Platform;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_deletion_hides_platform_but_keeps_its_record_and_audit(): void
    {
        $platform = Platform::create([
            'name' => 'Partner', 'promotion_url' => 'https://example.test', 'rating' => 5, 'featured_position' => 1,
        ]);
        $platform->forceFill(['active_query_revision' => 1])->save();
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create([
            'name' => 'Platform operator', 'permissions' => ['platforms.view', 'platforms.delete'],
        ]));

        $this->actingAs($operator, 'web');
        Livewire::test(ListPlatforms::class)->assertCanSeeTableRecords([$platform])
            ->callAction(TestAction::make('delete')->table($platform))->assertHasNoErrors()
            ->assertCanNotSeeTableRecords([$platform]);
        $this->assertDatabaseHas('platforms', ['id' => $platform->id, 'manually_disabled' => true, 'featured_position' => null]);
        $this->assertDatabaseHas('platform_audits', ['platform_id' => $platform->id, 'user_id' => $operator->id, 'action' => 'deleted']);
        $this->getJson('/api/v1/platforms/'.$platform->id)->assertNotFound();
    }

    public function test_read_only_operator_cannot_delete_platform(): void
    {
        $platform = Platform::create(['name' => 'Partner', 'promotion_url' => 'https://example.test', 'rating' => 5]);
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => 'Viewer', 'permissions' => ['platforms.view']]));

        $this->expectException(HttpException::class);
        app(PlatformAdministration::class)->archive($platform, $operator);
    }
}
