<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformReviewResource\Pages\ListPlatformReviews;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformReview;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformParticipationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_members_can_post_and_only_approved_reviews_are_public(): void
    {
        $platform = $this->platform();
        $member = Member::create(['phone' => '11900000001', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $path = '/api/v1/platforms/'.$platform->id.'/reviews';
        $payload = ['request_id' => (string) Str::uuid(), 'rating' => 2, 'body' => '我的实际使用体验。'];
        $this->postJson($path, $payload)->assertForbidden();
        PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => 'user-account',
            'query_phone' => $member->phone, 'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0, 'baseline_observed_at' => now(),
        ]);
        $id = $this->postJson($path, $payload)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertDatabaseHas('platform_reviews', ['id' => $id, 'display_name' => '119****0001']);
        PlatformReview::findOrFail($id)->forceFill(['display_name' => 'Membro LEGACY01'])->save();
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $id);
        $second = $this->postJson($path, [...$payload, 'request_id' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/platforms/'.$platform->id)->assertOk()->assertJsonPath('data.review_count', 0);

        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '评价查看', 'permissions' => ['platforms.view-reviews']]));
        $this->actingAs($operator, 'web')->get('/admin/platform-reviews')->assertOk();
        $approval = TestAction::make('approve')->table(PlatformReview::findOrFail($id));
        Livewire::test(ListPlatformReviews::class)->assertActionHidden($approval);
        $operator->roles()->attach(AdminRole::create(['name' => '评价审核', 'permissions' => ['platforms.moderate-reviews']]));
        Livewire::test(ListPlatformReviews::class)->callAction($approval)->assertHasNoActionErrors();
        Livewire::test(ListPlatformReviews::class)->callAction(TestAction::make('reject')->table(PlatformReview::findOrFail($second)), [
            'reason' => '内容需补充',
        ])->assertHasNoActionErrors();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rating', 2)
            ->assertJsonPath('data.0.source', 'member')->assertJsonPath('data.0.display_name', '119****0001')
            ->assertJsonMissingPath('data.0.member')->assertJsonMissingPath('data.0.phone')->assertDontSee($member->phone);
        $this->getJson('/api/v1/platforms/'.$platform->id)->assertOk()
            ->assertJsonPath('data.review_count', 1)->assertJsonPath('data.rating', '4.8');

        PlatformReview::findOrFail($id)->forceFill([
            'created_at' => '2026-11-21 03:00:00', 'published_at' => '2026-11-22 02:59:59',
        ])->save();
        PlatformReview::findOrFail($second)->forceFill(['published_at' => '2026-11-22 02:59:59'])->save();
        $this->getJson($path.'?date_from=2026-11-21&date_to=2026-11-21')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson($path.'?date_from=2026-11-22')->assertOk()->assertJsonPath('total', 0);

        $platform->forceFill(['manually_disabled' => true])->save();
        $this->getJson($path)->assertNotFound();
        $this->assertDatabaseHas('platform_reviews', ['id' => $second, 'status' => 'rejected', 'reviewer_id' => $operator->id]);
    }

    public function test_favorites_are_idempotent_and_unavailable_platforms_are_hidden(): void
    {
        $platform = $this->platform();
        $member = Member::create(['phone' => '11900000001', 'password' => 'member-password']);
        $path = '/api/v1/platform-favorites';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->putJson($path.'/'.$platform->id)->assertNoContent();
        $this->putJson($path.'/'.$platform->id)->assertNoContent();
        $this->getJson($path.'/'.$platform->id)->assertOk()->assertJsonPath('data.favorite', true);
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.id', $platform->id)->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('platform_favorites', 1);
        $platform->forceFill(['manually_disabled' => true])->save();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($path.'/'.$platform->id)->assertNoContent();
        $this->deleteJson($path.'/'.$platform->id)->assertNoContent();
        $this->getJson($path.'/'.$platform->id)->assertOk()->assertJsonPath('data.favorite', false);
        $this->assertDatabaseEmpty('platform_favorites');
    }

    private function platform(): Platform
    {
        $platform = Platform::create(['name' => 'Test platform', 'promotion_url' => 'https://example.test', 'rating' => '4.8']);
        $platform->forceFill(['active_query_revision' => 1])->save();

        return $platform;
    }
}
