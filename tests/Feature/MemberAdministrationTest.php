<?php

namespace Tests\Feature;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Members\MemberAdministration;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MemberAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operators_can_change_phone_and_password_with_audits_and_session_revocation(): void
    {
        $member = Member::create(['phone' => '11912345678', 'password' => 'previous-password', 'phone_verified_at' => now()]);
        $operator = $this->operator();
        $this->actingAs($operator, 'web')->get('/admin/members')->assertOk();

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('change_phone')->table($member), ['phone' => '+55 (11) 91234-5679'])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('reset_password')->table($member), [
                'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
            ])->assertHasNoActionErrors();

        $member->refresh();
        $this->assertSame('11912345679', $member->phone);
        $this->assertNull($member->phone_verified_at);
        $this->assertSame(2, $member->session_version);
        $this->assertTrue(Hash::check('replacement-password', $member->password));
        $this->assertFalse(Hash::check('previous-password', $member->password));
        $phoneAudit = DB::table('admin_audits')->where('action', 'member.phone_changed')->sole();
        $details = json_decode($phoneAudit->details, true);
        ksort($details);
        $this->assertSame(['new_phone' => '11912345679', 'previous_phone' => '11912345678'], $details);
        $passwordAudit = DB::table('admin_audits')->where('action', 'member.password_reset')->sole();
        $this->assertNull($passwordAudit->details);

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_points_adjustments_are_idempotent_and_cannot_overdraw_the_wallet(): void
    {
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $operator = $this->operator();
        $service = app(MemberAdministration::class);
        $command = ['direction' => 'credit', 'amount' => 35, 'reason' => '测试活动补发', 'operation_key' => (string) Str::uuid()];
        $first = $service->adjustPoints($member, $command, $operator);
        $this->assertTrue($first->is($service->adjustPoints($member, $command, $operator)));
        $this->assertDatabaseCount('point_entries', 1);
        $this->assertSame(35, $member->refresh()->points_balance);

        try {
            $service->adjustPoints($member, [
                ...$command, 'direction' => 'debit', 'amount' => 36, 'operation_key' => (string) Str::uuid(),
            ], $operator);
            $this->fail('Expected an insufficient balance validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertDatabaseCount('point_entries', 1);
        $this->assertSame(35, $member->refresh()->points_balance);
        $this->actingAs($operator, 'web')->get('/admin/point-entries')->assertOk()->assertSee('测试活动补发');
        $this->get('/admin/members')->assertOk();
        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('adjust_points')->table($member), [
                'direction' => 'debit', 'amount' => 5, 'reason' => '补发修正',
            ])->assertHasNoActionErrors();
        $this->assertSame(30, $member->refresh()->points_balance);
        $this->assertDatabaseCount('point_entries', 2);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '会员与积分', 'permissions' => [
            'members.view', 'members.change-phone', 'members.reset-password', 'members.adjust-points', 'points.view',
        ]]));

        return $operator;
    }
}
