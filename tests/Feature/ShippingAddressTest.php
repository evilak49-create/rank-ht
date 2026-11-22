<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_uses_the_confirmed_address_version_and_retries_keep_the_order_snapshot(): void
    {
        $url = '/api/v1/shipping-address';
        $this->getJson($url)->assertUnauthorized();
        $member = Member::create(['phone' => '11900000001', 'password' => 'member-password']);
        $member->forceFill(['available_balance_cents' => 3000])->save();
        $product = Product::create(['name' => 'Product', 'points_price' => 10, 'price_cents' => 1000, 'is_active' => true]);
        $product->forceFill(['stock' => 2])->save();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);

        $address = [
            'recipient' => 'Maria', 'phone' => '+55 (11) 91234-5678', 'postal_code' => '01310-100',
            'state' => 'sp', 'city' => 'São Paulo', 'district' => 'Bela Vista', 'street' => 'Av. Paulista', 'number' => '10',
        ];
        $checkout = ['product_id' => $product->id, 'price_cents' => 1000, 'request_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/shop/orders', $checkout + ['address_version' => 1])->assertConflict();
        $this->putJson($url, $address)->assertOk()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.masked_phone', '*******5678')->assertJsonPath('data.state', 'SP')->assertJsonMissingPath('data.phone');
        $this->getJson($url.'?for_edit=1')->assertOk()->assertJsonPath('data.phone', '11912345678');
        $this->putJson($url, $address)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertStringNotContainsString('Av. Paulista', DB::table('shipping_addresses')->sole()->address);

        $first = $this->postJson('/api/v1/shop/orders', $checkout + ['address_version' => 1])
            ->assertCreated()->assertJsonPath('data.address.number', '10')->json('data.id');
        $this->putJson($url, [...$address, 'number' => '20'])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/shop/orders', $checkout + ['address_version' => 1])
            ->assertCreated()->assertJsonPath('data.id', $first)->assertJsonPath('data.address.number', '10');
        $this->postJson('/api/v1/shop/orders', [...$checkout, 'request_id' => (string) Str::uuid(), 'address_version' => 1])
            ->assertConflict();
        $this->getJson('/api/v1/shop/orders/'.$first)->assertOk()->assertJsonPath('data.address.number', '10');
        $this->getJson('/api/v1/cash-wallet')->assertOk()->assertJsonPath('data.available_balance_cents', 2000);
        $this->assertDatabaseHas('cash_balance_entries', ['source' => 'shop_payment', 'source_id' => $first, 'amount_cents' => -1000]);
        $this->assertDatabaseCount('cash_balance_entries', 1);
        $this->assertSame(2000, $member->refresh()->available_balance_cents);
        $this->assertSame(1, $product->refresh()->stock);
        $this->assertDatabaseCount('shipping_addresses', 1);
        Auth::forgetGuards();
        $other = Member::create(['phone' => '11900000002', 'password' => 'member-password']);
        $this->actingAs($other, 'member');
        $this->getJson($url.'?for_edit=1')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/shop/orders/'.$first)->assertNotFound();
    }
}
