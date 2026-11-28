<?php

namespace Tests\Feature;

use App\Auth\AdminPermissions;
use App\Filament\Resources\CashBalanceEntryResource\Pages\ListCashBalanceEntries;
use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sidebar_groups_related_features_together(): void
    {
        $this->signInWith(array_keys(AdminPermissions::LABELS));

        $this->get('/admin')->assertOk();

        $groups = $this->groupedItems();
        $this->assertSame(['会员管理', '平台管理', '域名管理', '资金管理', '活动管理', '内容管理', '客服管理', '系统管理'], array_keys($groups));
        $this->assertSame(['域名列表'], $groups['域名管理']);
        $this->assertContains('返利管理', $groups['平台管理']);
        $this->assertContains('救援管理', $groups['平台管理']);
        $this->assertContains('出款管理', $groups['资金管理']);
        $this->assertContains('三方渠道', $groups['资金管理']);
        $this->assertContains('对话列表', $groups['客服管理']);
        foreach (['返利配置', '救援配置', '商品管理', '商品订单', '积分流水', '保障资格'] as $entry) {
            $this->assertNotContains($entry, array_merge(...array_values($groups)));
        }

        $this->get('/admin/cashback-settlements')->assertOk()->assertSee('返利配置');
        $this->get('/admin/rescue-fund-claims')->assertOk()->assertSee('救援配置');
        $this->get('/admin/products')->assertNotFound();
        $this->get('/admin/shop-orders')->assertNotFound();
    }

    public function test_consolidated_sidebar_only_lists_authorized_entries(): void
    {
        $this->signInWith(['content.manage']);

        $this->get('/admin')->assertOk();

        $this->assertSame(['内容管理' => ['Banner', '公告管理', '帮助中心']], $this->groupedItems());
        $this->get('/admin/support-settings')->assertForbidden();
        $this->get('/admin/rescue-fund-configuration')->assertForbidden();
        $this->get('/admin/cash-balance-entries')->assertForbidden();
    }

    public function test_operator_can_find_and_filter_cash_entries(): void
    {
        $this->signInWith(['points.view', 'funds.view', 'pix.view']);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        foreach (['rescue_fund' => 1_500, 'rebate' => 2_500] as $source => $amount) {
            DB::table('cash_balance_entries')->insert([
                'member_id' => $member->id, 'source' => $source, 'source_id' => 73,
                'amount_cents' => $amount, 'balance_after_cents' => $amount, 'created_at' => now(),
            ]);
        }

        $this->get('/admin/cash-balance-entries')->assertOk();
        Livewire::test(ListCashBalanceEntries::class)->searchTable($member->phone)
            ->filterTable('source', 'rescue_fund')->assertSee('R$ 15.00')->assertDontSee('R$ 25.00');
    }

    public function test_configuration_permission_alone_does_not_expose_financial_records(): void
    {
        $this->signInWith(['points.manage-rules']);
        $this->get('/admin/rebate-configuration')->assertOk();
        $this->get('/admin/cashback-settlements')->assertForbidden();
        $this->get('/admin/rescue-fund-claims')->assertForbidden();
    }

    public function test_platform_and_member_rows_each_render_one_named_action_menu(): void
    {
        $this->signInWith(array_keys(AdminPermissions::LABELS));
        Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        Platform::create(['name' => 'Menu Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.8']);

        foreach ([ListPlatforms::class => 'configure_query', ListMembers::class => 'reset_password'] as $page => $entry) {
            $component = Livewire::test($page)->assertSee('操作');
            $actions = $component->instance()->getTable()->getRecordActions();
            $menus = array_values(array_filter($actions, fn ($action): bool => $action instanceof ActionGroup));
            $this->assertCount(1, $menus);
            $menu = $menus[0];
            $this->assertInstanceOf(ActionGroup::class, $menu);
            $this->assertSame('操作', $menu->getLabel());
            $this->assertArrayHasKey($entry, $menu->getFlatActions());
            if ($page === ListPlatforms::class) {
                $names = array_keys($menu->getFlatActions());
                $this->assertGreaterThan(array_search('disable', $names, true), array_search('delete', $names, true));
            }
        }
    }

    private function signInWith(array $permissions): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => 'Navigation operator', 'permissions' => $permissions]));
        $this->actingAs($operator, 'web');
    }

    private function groupedItems(): array
    {
        return collect(Filament::getNavigation())
            ->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()))
            ->mapWithKeys(fn (NavigationGroup $group): array => [
                $group->getLabel() => collect($group->getItems())
                    ->map(fn (NavigationItem $item): string => $item->getLabel())->values()->all(),
            ])->all();
    }
}
