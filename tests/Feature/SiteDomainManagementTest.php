<?php

namespace Tests\Feature;

use App\Filament\Resources\PromotionChannelResource\Pages\CreatePromotionChannel;
use App\Filament\Resources\SiteDomainResource\Pages\CreateSiteDomain;
use App\Models\AdminRole;
use App\Models\PromotionChannel;
use App\Models\User;
use App\Pwa\PwaConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SiteDomainManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_permissions_and_creation_gate_the_channel_url_and_pwa_selection(): void
    {
        $admin = User::factory()->create(['panel_enabled' => true]);
        $admin->roles()->attach(AdminRole::create(['name' => 'Channel operator', 'permissions' => [
            'members.view', 'members.manage-promotion-channels', 'system.manage-pwa',
        ]]));
        $this->actingAs($admin, 'web')->get('/admin/domains')->assertForbidden();

        Livewire::test(CreatePromotionChannel::class)->fillForm(['name' => 'Needs a domain'])
            ->call('create')->assertHasFormErrors(['name']);
        $this->assertDatabaseCount('promotion_channels', 0);

        $admin->roles()->attach(AdminRole::create(['name' => 'Domain operator', 'permissions' => ['system.manage-domains']]));
        $this->get('/admin/domains')->assertOk();
        $this->get('/admin/domains/create')->assertOk();
        Livewire::test(CreateSiteDomain::class)->fillForm(['host' => 'PROMO.example.test', 'purpose' => 'channel'])
            ->call('create')->assertHasNoErrors();
        Livewire::test(CreateSiteDomain::class)->fillForm(['host' => 'install.example.test', 'purpose' => 'pwa'])
            ->call('create')->assertHasNoErrors();

        Livewire::test(CreatePromotionChannel::class)->fillForm(['name' => 'Campaign'])
            ->call('create')->assertHasNoErrors();
        $channel = PromotionChannel::query()->sole();
        $this->assertSame('https://promo.example.test/go/'.$channel->code, $channel->promotionUrl());
        $this->get('/go/'.$channel->code)->assertRedirect('/');

        $pwa = app(PwaConfiguration::class);
        $pwa->save(array_replace($pwa->read(), ['host' => 'install.example.test']));
        $this->assertSame('install.example.test', $pwa->read()['host']);
        $this->assertNotContains('promo.example.test', $pwa->domains());
        $this->expectException(ValidationException::class);
        $pwa->save(array_replace($pwa->read(), ['host' => 'promo.example.test']));
    }
}
