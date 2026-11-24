<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Member;
use App\Models\User;
use App\RedPackets\InvitationRewards;
use App\RedPackets\RedPackets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReworkActivitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsettled_invites_use_the_latest_percentage_and_the_round_keeps_its_threshold(): void
    {
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '红包运营', 'permissions' => ['red-packets.manage']]));
        $inviter = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $invitee = Member::create(['phone' => '11912345679', 'password' => 'member-password']);
        $packets = app(RedPackets::class);
        $packets->saveRatios(['initial_min' => '90', 'initial_max' => '90', 'invite_min' => '50', 'invite_max' => '50'], $operator);
        $round = $packets->open($inviter->refresh(), (string) Str::uuid());
        $this->assertSame(9000, $round->initial_cents);

        $packets->saveThreshold('150', $operator);
        $packets->saveRatios(['initial_min' => '80', 'initial_max' => '80', 'invite_min' => '60', 'invite_max' => '60'], $operator);
        app(InvitationRewards::class)->register($invitee, $packets->inviteCode($inviter));
        $this->assertSame(10000, $round->refresh()->threshold_cents);
        $this->assertDatabaseHas('red_packet_invites', ['red_packet_round_id' => $round->id, 'reward_cents' => 600]);
    }
}
