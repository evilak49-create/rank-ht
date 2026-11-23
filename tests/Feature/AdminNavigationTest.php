<?php

namespace Tests\Feature;

use App\Auth\AdminPermissions;
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
use Livewire\Livewire;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sidebar_groups_related_features_together(): void
    {
        $this->signInWith(array_keys(AdminPermissions::LABELS));

        $this->get('/admin')->assertOk();

        $this->assertSame([
            '会员与活动' => ['会员列表', '签到记录', '红包轮次', '红包邀请记录'],
            '平台与保障' => ['平台列表', '平台评价', 'AI 评价批次', '保障条款', '保障资格', '申诉案件'],
            '积分与配置' => ['返利配置', '救援金配置', '积分流水', '兑换查询记录', '商品管理', '商品订单', '平台资金兑换', 'Pix 提现审核'],
            '内容与客服' => ['Banner', '公告管理', '帮助中心', '客服设置', '常见问题'],
            '系统设置' => ['后台账号', '角色权限', '后台授权审计', '业务时区', 'OSS 配置', 'AI 配置', '会员验证码'],
        ], $this->groupedItems());
    }

    public function test_consolidated_sidebar_only_lists_authorized_entries(): void
    {
        $this->signInWith(['content.manage']);

        $this->get('/admin')->assertOk();

        $this->assertSame(['内容与客服' => ['Banner', '公告管理', '帮助中心']], $this->groupedItems());
        $this->get('/admin/support-settings')->assertForbidden();
        $this->get('/admin/rescue-fund-configuration')->assertForbidden();
    }

    public function test_platform_and_member_rows_each_render_one_named_action_menu(): void
    {
        $this->signInWith(array_keys(AdminPermissions::LABELS));
        Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        Platform::create(['name' => 'Menu Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.8']);

        foreach ([ListPlatforms::class => 'configure_query', ListMembers::class => 'reset_password'] as $page => $entry) {
            $component = Livewire::test($page)->assertSee('操作');
            $actions = $component->instance()->getTable()->getRecordActions();
            $this->assertCount(1, $actions);
            $menu = array_values($actions)[0];
            $this->assertInstanceOf(ActionGroup::class, $menu);
            $this->assertSame('操作', $menu->getLabel());
            $this->assertArrayHasKey($entry, $menu->getFlatActions());
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
