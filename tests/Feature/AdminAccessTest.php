<?php

namespace Tests\Feature;

use App\Auth\AdminAccess;
use App\Filament\Resources\AdminAccountResource\Pages\ListAdminAccounts;
use App\Filament\Resources\AdminRoleResource\Pages\ListAdminRoles;
use App\Models\AdminRole;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_multiple_roles_and_cannot_remove_the_last_access_manager(): void
    {
        $operator = User::factory()->create(['username' => 'root-owner']);
        $operator->forceFill(['panel_enabled' => true])->save();
        $ownerRole = AdminRole::create(['name' => 'Owner', 'permissions' => [
            'system.manage-roles', 'system.manage-admins', 'system.view-admin-audit', 'members.view', 'points.view',
        ]]);
        $operator->roles()->attach($ownerRole);

        $this->actingAs($operator, 'web');
        $this->get('/admin/admin-roles')->assertOk();
        $this->get('/admin/admin-accounts')->assertOk();
        Livewire::test(ListAdminRoles::class)->callAction('create_role', [
            'name' => 'Members', 'permissions' => ['members.view'],
        ])->assertHasNoActionErrors();
        $members = AdminRole::where('name', 'Members')->sole();
        $points = AdminRole::create(['name' => 'Points', 'permissions' => ['points.view']]);
        Livewire::test(ListAdminAccounts::class)->callAction('create_account', [
            'username' => 'Ops.1', 'password' => 'test-password',
        ])->assertHasNoActionErrors();
        $staff = User::where('username', 'ops.1')->sole();
        Livewire::test(ListAdminAccounts::class)->callAction(TestAction::make('assign_roles')->table($staff), [
            'role_ids' => [$members->id, $points->id],
        ])->assertHasNoActionErrors();
        $this->assertTrue($staff->hasPermission('members.view'));
        $this->assertTrue($staff->hasPermission('points.view'));
        $this->withSession(['password_hash_web' => null])->actingAs($staff, 'web')->get('/admin/members')->assertOk();
        $this->get('/admin/admin-roles')->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs($operator, 'web');
        Livewire::test(ListAdminAccounts::class)->callAction(TestAction::make('assign_roles')->table($staff), [
            'role_ids' => [$points->id],
        ])->assertHasNoActionErrors();
        $this->assertFalse($staff->hasPermission('members.view'));
        $this->assertTrue($staff->hasPermission('points.view'));
        $this->withSession(['password_hash_web' => null])->actingAs($staff, 'web')->get('/admin/members')->assertForbidden();
        $this->get('/admin/cashback-settlements')->assertOk();
        $this->get('/admin/point-entries')->assertNotFound();
        $this->withSession(['password_hash_web' => null])->actingAs($operator, 'web');
        $this->assertDatabaseHas('admin_access_audits', ['user_id' => $operator->id, 'target_user_id' => $staff->id, 'action' => 'roles.changed']);
        $this->get('/admin/admin-access-audits')->assertOk();

        Livewire::test(ListAdminAccounts::class)->callAction(TestAction::make('assign_roles')->table($operator), [
            'role_ids' => [$members->id],
        ])->assertHasActionErrors(['role_ids']);
        $this->assertTrue($operator->hasPermission('system.manage-roles'));
        Livewire::test(ListAdminRoles::class)->callAction(TestAction::make('edit_role')->table($ownerRole), [
            'name' => 'Owner', 'permissions' => ['system.manage-roles', 'system.view-admin-audit'],
        ])->assertHasActionErrors(['permissions']);
        Livewire::test(ListAdminAccounts::class)->callAction(TestAction::make('disable')->table($operator), [
            'confirm' => true,
        ])->assertHasActionErrors(['confirm']);
        $this->assertTrue($operator->refresh()->panel_enabled);
        $this->assertTrue($operator->hasPermission('system.manage-admins'));
    }

    public function test_account_manager_cannot_take_over_a_more_privileged_account(): void
    {
        $staff = User::factory()->create(['username' => 'operator']);
        $staff->forceFill(['panel_enabled' => true])->save();
        $staff->roles()->attach(AdminRole::create(['name' => 'Accounts', 'permissions' => ['system.manage-admins']]));
        $owner = User::factory()->create(['username' => 'root-owner']);
        $owner->forceFill(['panel_enabled' => true])->save();
        $owner->roles()->attach(AdminRole::create(['name' => 'Owner', 'permissions' => ['system.manage-admins', 'system.manage-roles']]));
        $this->actingAs($staff, 'web');
        Livewire::test(ListAdminAccounts::class)->assertActionHidden(TestAction::make('reset_password')->table($owner));
        $originalHash = $owner->password;
        try {
            app(AdminAccess::class)->resetPassword($owner, 'abcde', $staff);
            $this->fail('The account manager cannot reset the owner password.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($originalHash, $owner->refresh()->password);
        app(AdminAccess::class)->resetPassword($staff, 'abcde', $staff);
        $this->assertTrue(Hash::check('abcde', $staff->refresh()->password));
        $this->assertStringNotContainsString('abcde', DB::table('admin_access_audits')->get()->toJson());
    }
}
