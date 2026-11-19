<?php

namespace Tests\Feature;

use App\Models\FundRateVersion;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformFundOrder;
use App\Models\PointEntry;
use App\Models\PointQuote;
use App\Models\PointRule;
use App\Models\Product;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_bootstrap_returns_an_uncacheable_cookie_response(): void
    {
        $response = $this->getJson('/api/v1/auth/csrf-cookie')->assertNoContent()
            ->assertCookie('XSRF-TOKEN')->assertCookie(config('session.cookie'));

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->getCookie(config('session.cookie'))->isHttpOnly());
    }

    public function test_search_matches_public_products_by_name(): void
    {
        $shown = Product::create(['name' => 'Fone Bluetooth', 'points_price' => 10, 'is_active' => true]);
        Product::create(['name' => 'Fone USB', 'points_price' => 10, 'is_active' => false]);
        Product::create(['name' => 'Caneca', 'points_price' => 10, 'is_active' => true]);

        $this->getJson('/api/v1/shop/products?search=Fone')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shown->id);
        $this->getJson('/api/v1/shop/products?search[]=Fone')->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_storefront_exposes_the_current_funds_rate_without_a_member_session(): void
    {
        $rate = FundRateVersion::create(['cents_per_point' => 125]);

        $this->getJson('/api/v1/platform-funds/rate')->assertOk()->assertExactJson(['data' => [
            'rate_version' => $rate->id, 'cents_per_point' => 125, 'currency' => 'BRL',
        ]]);
    }

    public function test_write_requests_enforce_csrf_outside_the_testing_environment(): void
    {
        $this->app->instance('env', 'production');
        $token = Str::random(40);
        $this->withSession(['_token' => $token]);

        $this->postJson('/api/v1/auth/login')->assertStatus(419);
        $this->postJson('/api/v1/auth/login', [], ['X-CSRF-TOKEN' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_redemption_history_is_sorted_filtered_and_scoped_to_the_session(): void
    {
        $this->freezeSecond();
        $url = '/api/v1/shop/redemptions';
        $this->getJson($url)->assertUnauthorized();

        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $other = Member::create(['phone' => '11912345679', 'password' => 'member-password']);
        $product = Product::create(['name' => 'Fone', 'points_price' => 10]);
        foreach ([$member, $other] as $owner) {
            ShopOrder::create([
                'member_id' => $owner->id, 'product_id' => $product->id, 'product_name' => $product->name,
                'order_number' => Str::uuid(), 'request_id' => Str::uuid(), 'points_price' => 10,
                'address' => ['recipient' => 'Private recipient'], 'status' => 'shipped',
            ]);
        }
        $platform = Platform::create(['name' => 'Example', 'promotion_url' => 'https://example.com', 'rating' => '4.0']);
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => 'private-account',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0,
            'baseline_valid_bet_cents' => 0, 'baseline_observed_at' => now(),
        ]);
        PlatformFundOrder::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_binding_id' => $binding->id,
            'fund_rate_version_id' => FundRateVersion::firstOrFail()->id, 'platform_account_id' => $binding->platform_account_id,
            'order_number' => Str::uuid(), 'request_id' => Str::uuid(), 'points' => 5,
            'cents_per_point' => 100, 'amount_cents' => 500, 'status' => 'pending',
        ]);

        $this->actingAs($member, 'member')->withSession(['member_session_version' => $member->session_version ?? 0]);
        $response = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'funds')->assertJsonPath('data.0.name', 'Example')
            ->assertJsonPath('data.0.points', 5)->assertJsonPath('data.1.type', 'goods')
            ->assertJsonPath('data.1.points', 10)->assertJsonMissingPath('data.1.address')
            ->assertJsonMissingPath('data.0.platform_account_id');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        $this->getJson($url.'?type=goods')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fone')->assertJsonPath('data.0.status', 'shipped');
        $this->getJson($url.'?type=funds')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url.'?type=invalid')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson($url.'?page=2')->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/platform-bindings/'.$binding->id)->assertOk()
            ->assertJsonPath('data.platform_name', 'Example')->assertJsonPath('data.redeemed_points', 0)
            ->assertJsonPath('data.last_redeemed_at', null)
            ->assertJsonPath('data.masked_platform_account', '****ount')
            ->assertJsonMissingPath('data.platform_account_id')->assertJsonMissingPath('data.query_phone');
        $this->getJson('/api/v1/platform-funds/orders/'.$response->json('data.0.id'))->assertOk()
            ->assertJsonPath('data.platform_name', 'Example')->assertJsonPath('data.masked_platform_account', '****ount')
            ->assertJsonMissingPath('data.platform_account_id');

        $quoteData = [
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_binding_id' => $binding->id,
            'point_rule_id' => PointRule::firstOrFail()->id, 'baseline_version' => 1,
            'estimated_points' => 7, 'eligibility' => 'eligible', 'expires_at' => now()->addMinute(),
            'snapshot' => ['observed_deposit_cents' => 12000, 'observed_valid_bet_cents' => 24000, 'remainder_units' => 0],
        ];
        $quote = PointQuote::create($quoteData);
        PointQuote::create([...$quoteData, 'estimated_points' => 13]);
        $entry = PointEntry::create([
            'member_id' => $member->id, 'operation_key' => Str::uuid(), 'direction' => 'credit',
            'amount' => 7, 'balance_before' => 0, 'balance_after' => 7,
            'reason' => 'Deposit redemption', 'source' => 'deposit', 'source_id' => $quote->id,
        ]);
        $quote->forceFill(['confirmed_at' => now(), 'point_entry_id' => $entry->id])->save();
        $this->getJson('/api/v1/platform-bindings')->assertOk()->assertJsonPath('data.0.redeemed_points', 7)
            ->assertJsonPath('data.0.last_redeemed_at', now()->toISOString())
            ->assertJsonPath('data.0.masked_platform_account', '****ount');
        $this->getJson('/api/v1/point-quotes/'.$quote->id)->assertOk()
            ->assertJsonPath('data.observed_deposit_cents', 12000)->assertJsonPath('data.observed_valid_bet_cents', 24000)
            ->assertJsonMissingPath('data.remainder_units');

        Auth::forgetGuards();
        $this->actingAs($other, 'member')->getJson('/api/v1/platform-bindings/'.$binding->id)->assertNotFound();
    }
}
