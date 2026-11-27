<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\PromotionChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PromotionChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_list_can_create_a_channel_and_save_daily_expenses(): void
    {
        $admin = \App\Models\User::factory()->create();
        $admin->forceFill(['panel_enabled' => true])->save();
        $admin->roles()->attach(\App\Models\AdminRole::create([
            'name' => 'Promotion manager', 'permissions' => ['members.view', 'members.manage-promotion-channels'],
        ]));
        $this->actingAs($admin, 'web');
        $this->get('/admin/promotion-channels')->assertOk();
        $this->get('/admin/promotion-channels/create')->assertOk();
        \Livewire\Livewire::test(\App\Filament\Resources\PromotionChannelResource\Pages\CreatePromotionChannel::class)
            ->fillForm(['name' => 'Ground team', 'notes' => 'East entrance'])
            ->call('create')->assertHasNoErrors();
        $channel = PromotionChannel::query()->sole();
        \Livewire\Livewire::test(\App\Filament\Resources\PromotionChannelResource\Pages\ListPromotionChannels::class)
            ->assertCanSeeTableRecords([$channel])
            ->callAction(\Filament\Actions\Testing\TestAction::make('expense')->table($channel), data: [
                'date' => '2026-11-27', 'amount' => '25.00',
            ])->assertHasNoErrors();
        $this->assertDatabaseHas('promotion_expenses', ['promotion_channel_id' => $channel->id, 'amount_cents' => 2500]);
    }

    public function test_report_counts_first_post_binding_deposit_once_on_its_business_date(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-27 12:00:00', 'UTC'));
        $channel = PromotionChannel::create(['name' => 'Facebook']);
        $member = Member::create([
            'phone' => '11912345678', 'password' => 'example-pass-123', 'promotion_channel_id' => $channel->id,
        ]);
        $platform = \App\Models\Platform::create([
            'name' => 'Partner', 'promotion_url' => 'https://example.test', 'rating' => 5, 'network' => 'panda',
        ]);
        $binding = \App\Models\PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => '123',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0,
            'baseline_observed_at' => now(),
        ]);
        foreach (['2026-11-27 11:00:00', '2026-11-28 02:00:00', '2026-11-28 18:00:00'] as $index => $time) {
            \Illuminate\Support\Facades\DB::table('platform_recharges')->insert([
                'platform_binding_id' => $binding->id, 'network' => 'panda', 'external_id' => (string) $index,
                'amount_cents' => 500, 'recharged_at' => $time, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $platform->forceFill(['active_query_revision' => 1, 'active_query' => []])->save();
        \Illuminate\Support\Facades\Queue::fake();
        $this->artisan('promotion-recharges:dispatch')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncPromotionRecharges::class,
            fn ($job): bool => $job->bindingId === $binding->id && $job->page === 1);

        $report = app(\App\Promotion\PromotionReport::class);
        $report->saveExpense($channel, '2026-11-27', '300.00');
        $friday = $report->query(PromotionChannel::query(), '2026-11-27', '2026-11-27')->sole();
        $saturday = $report->query(PromotionChannel::query(), '2026-11-28', '2026-11-28')->sole();
        $this->assertEquals(1, $friday->deposit_count);
        $this->assertEquals(1, $friday->registration_count);
        $this->assertEquals(30000, $friday->expense_cents);
        $this->assertEquals(0, $saturday->deposit_count);
        $this->assertNull($saturday->expense_cents);
    }

    public function test_latest_promotion_visit_is_deduplicated_and_persisted_on_registration(): void
    {
        $first = PromotionChannel::create(['name' => 'Facebook']);
        $last = PromotionChannel::create(['name' => 'SMS']);
        $visit = $this->get("/go/{$first->code}")->assertRedirect('/');
        $this->withCookie('promotion_visit', $visit->getCookie('promotion_visit')->getValue());
        $visit = $this->get("/go/{$last->code}")->assertRedirect('/');
        $this->withCookie('promotion_visit', $visit->getCookie('promotion_visit')->getValue());
        $this->get("/go/{$last->code}")->assertRedirect('/');
        $this->assertDatabaseCount('promotion_visits', 2);

        Log::spy();
        $code = null;
        $this->postJson('/api/v1/auth/sms-code', [
            'phone' => '11912345678', 'purpose' => 'register',
        ])->assertAccepted();
        Log::shouldHaveReceived('debug')->withArgs(
            function (string $message, array $context) use (&$code): bool {
                $code = $context['code'] ?? null;

                return $message === 'Local SMS simulation' && is_string($code);
            }
        );

        $this->withCredentials()->postJson('/api/v1/auth/register', [
            'phone' => '11912345678', 'password' => 'example-pass-123', 'code' => $code,
        ])->assertCreated();
        $this->assertEquals($last->id, Member::query()->sole()->promotion_channel_id);
    }
}
