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
            'address' => ['recipient' => 'Example', 'phone' => $member->phone, 'postal_code' => '01001-000',
                'state' => 'SP', 'city' => 'São Paulo', 'street' => 'Praça da Sé', 'number' => '10'],
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
        $this->actingAs($operator, 'web')->get('/admin/shop-orders')->assertOk();
        Livewire::test(ListShopOrders::class)->callAction(TestAction::make('ship')->table(ShopOrder::findOrFail($next)), [
            'carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-123',
        ])->assertHasNoActionErrors();

        $this->actingAs($member, 'member')->getJson("/api/v1/shop/orders/$next")
            ->assertOk()->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.tracking_number', 'TRACK-123');
    }
}
