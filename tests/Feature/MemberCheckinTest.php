<?php

namespace Tests\Feature;

use App\Activities\CheckinRules;
use App\Filament\Resources\BusinessTimezoneResource\Pages\ListBusinessTimezones;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberCheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_keeps_a_rounds_rule_version_and_never_rewards_the_same_day_twice(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '活动运营', 'permissions' => ['activities.manage-checkin']]));
        $rules = app(CheckinRules::class);
        $original = $rules->save([['days' => 2, 'points' => 5]], $operator);

        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(18, 0));
        $this->getJson('/api/v1/check-in')->assertOk()
            ->assertJsonPath('data.tiers', [['days' => 2, 'points' => 5]]);
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.rule_version', $original->id)
            ->assertJsonPath('data.streak', 1)->assertJsonPath('data.reward_points', 0);

        $updated = $rules->save([['days' => 1, 'points' => 9]], $operator);
        $this->travel(1)->day();
        $this->getJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.rule_version', $original->id)
            ->assertJsonPath('data.tiers', [['days' => 2, 'points' => 5]]);
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.reward_points', 5);
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.reward_points', 5);
        $this->assertSame(5, $member->refresh()->points_balance);

        $this->travel(1)->day();
        $this->getJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.rule_version', $updated->id)
            ->assertJsonPath('data.tiers', [['days' => 1, 'points' => 9]]);
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.rule_version', $updated->id)
            ->assertJsonPath('data.streak', 1)->assertJsonPath('data.reward_points', 9);
        $this->assertSame(14, $member->refresh()->points_balance);
        $this->assertDatabaseCount('point_entries', 2);
    }

    public function test_check_in_returns_all_reward_tiers_in_day_order(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '活动运营', 'permissions' => ['activities.manage-checkin']]));
        $tiers = [['days' => 2, 'points' => 5], ['days' => 7, 'points' => 25], ['days' => 15, 'points' => 100]];
        app(CheckinRules::class)->save(array_reverse($tiers), $operator);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        $this->getJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.tiers', $tiers);
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.tiers', $tiers);
    }

    public function test_a_timezone_change_waits_for_the_old_midnight_and_does_not_duplicate_a_day(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '时区运营', 'permissions' => [
            'activities.manage-checkin', 'activities.view-checkin', 'system.manage-business-timezone',
        ]]));
        app(CheckinRules::class)->save([['days' => 1, 'points' => 5]], $operator);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(23, 30));
        $this->actingAs($operator, 'web')->get('/admin/business-timezones')->assertOk();
        Livewire::test(ListBusinessTimezones::class)->callAction('schedule_timezone', [
            'timezone' => 'America/Los_Angeles',
        ])->assertHasNoActionErrors();
        $this->get('/admin/member-checkins')->assertOk();
        $this->assertDatabaseHas('business_timezones', [
            'timezone' => 'America/Los_Angeles', 'effective_at' => '2026-10-02 03:00:00',
        ]);

        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.timezone', 'America/Sao_Paulo');
        $this->travel(4)->hours();
        $this->getJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.business_date', '2026-10-01');
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.timezone', 'America/Sao_Paulo');
        $this->assertSame(5, $member->refresh()->points_balance);

        $this->travel(4)->hours();
        $this->postJson('/api/v1/check-in')->assertOk()->assertJsonPath('data.timezone', 'America/Los_Angeles');
        $this->assertSame(10, $member->refresh()->points_balance);
    }
}
