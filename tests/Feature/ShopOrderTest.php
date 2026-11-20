<?php

namespace Tests\Feature;

use App\Filament\Resources\ShopOrderResource\Pages\ListShopOrders;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\User;
use App\Shop\PhysicalShop;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ShopOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_is_idempotent_and_cancellation_restores_stock_and_points_once(): void
    {
        $product = Product::create(['name' => 'Example', 'points_price' => 12, 'stock' => 1, 'is_active' => true]);
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $member->forceFill(['points_balance' => 20])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        $request = [
            'product_id' => $product->id, 'request_id' => (string) Str::uuid(),
            'address' => ['recipient' => 'Maria', 'phone' => '11987654321', 'postal_code' => '01001-000',
                'state' => 'SP', 'city' => 'São Paulo', 'district' => 'Sé', 'street' => 'Praça da Sé',
                'number' => '10', 'complement' => 'Apto 12'],
        ];
        $this->postJson('/api/v1/shop/orders', [...$request, 'quantity' => 2])->assertUnprocessable();
        $id = $this->postJson('/api/v1/shop/orders', $request)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/shop/orders', $request)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(0, $product->refresh()->stock);
        $this->assertSame(8, $member->refresh()->points_balance);

        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => 'shop', 'permissions' => ['shop.cancel-orders', 'shop.view-orders', 'shop.ship-orders']]));
        $order = ShopOrder::findOrFail($id);
        $shop = app(PhysicalShop::class);
        $shop->cancel($order, '商品无法发出', $operator);
        $shop->cancel($order, '商品无法发出', $operator);

        $this->assertSame(1, $product->refresh()->stock);
        $this->assertSame(20, $member->refresh()->points_balance);
        $this->assertDatabaseCount('point_entries', 2);
        $this->getJson("/api/v1/shop/orders/$id")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $next = $this->postJson('/api/v1/shop/orders', [...$request, 'request_id' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $this->putJson('/api/v1/shipping-address', [...$request['address'], 'street' => 'Novo endereço'])->assertOk();

        $this->actingAs($operator, 'web')->get('/admin/shop-orders')->assertOk()->assertSee('查看详情');
        $nextOrder = ShopOrder::findOrFail($next);
        $details = Livewire::test(ListShopOrders::class)->mountAction(TestAction::make('view_details')->table($nextOrder));
        foreach ($request['address'] as $field => $value) {
            $details->assertSchemaComponentStateSet('address.'.$field, $value);
        }
        Livewire::test(ListShopOrders::class)
            ->assertActionHidden(TestAction::make('edit_tracking')->table($nextOrder))
            ->assertActionHidden(TestAction::make('edit_tracking')->table($order));
        Livewire::test(ListShopOrders::class)->callAction(TestAction::make('ship')->table($nextOrder), [
            'carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-123',
        ])->assertHasNoActionErrors();

        $this->actingAs($member, 'member')->getJson("/api/v1/shop/orders/$next")
            ->assertOk()->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.tracking_number', 'TRACK-123');

        $shippedOrder = $nextOrder->refresh();
        $shippedAt = $shippedOrder->shipped_at;
        $this->travel(1)->hour();

        $viewer = User::factory()->create(['panel_enabled' => true]);
        $viewer->roles()->attach(AdminRole::create(['name' => 'shop-viewer', 'permissions' => ['shop.view-orders']]));
        $this->actingAs($viewer, 'web');
        Livewire::test(ListShopOrders::class)
            ->assertActionHidden(TestAction::make('edit_tracking')->table($shippedOrder));

        $editor = User::factory()->create(['panel_enabled' => true]);
        $editor->roles()->attach(AdminRole::create(['name' => 'shipping', 'permissions' => ['shop.view-orders', 'shop.ship-orders']]));
        $this->actingAs($editor, 'web');
        Livewire::test(ListShopOrders::class)
            ->mountAction(TestAction::make('edit_tracking')->table($shippedOrder))
            ->assertActionDataSet(['carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-123'])
            ->setActionData(['carrier' => 'Correios', 'tracking_number' => 'TRACK-456'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEquals($shippedAt, $shippedOrder->refresh()->shipped_at);
        $this->assertSame($operator->id, $shippedOrder->user_id);
        $this->assertSame(0, $product->refresh()->stock);
        $this->assertSame(8, $member->refresh()->points_balance);
        $this->actingAs($member, 'member')->getJson("/api/v1/shop/orders/$next")->assertOk()
            ->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.carrier', 'Correios')
            ->assertJsonPath('data.tracking_number', 'TRACK-456');
    }
}
