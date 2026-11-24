<?php

namespace Tests\Feature;

use App\Content\Publishing;
use App\Filament\Resources\AnnouncementResource\Pages\ListAnnouncements;
use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_manager_only_releases_published_due_items(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '公告运营', 'permissions' => ['content.manage']]));
        $this->actingAs($operator, 'web')->get('/admin/announcements')->assertOk();
        Livewire::test(ListAnnouncements::class)
            ->callAction('create_article', ['title' => '已发布公告', 'body_html' => '<p><strong>查看最新消息</strong></p>'])
            ->assertHasNoActionErrors();
        $record = \App\Models\SiteArticle::query()->sole();
        $this->getJson('/api/v1/content/announcements/latest')->assertJsonPath('data', null);
        Livewire::test(ListAnnouncements::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('publish')->table($record))->assertHasNoActionErrors();
        app(Publishing::class)->article('announcement', null, [
            'title' => '历史定时公告', 'body' => '隐藏内容', 'published' => true, 'published_at' => now()->addDay(),
        ], $operator);
        $this->getJson('/api/v1/content/announcements/'.$record->id)->assertJsonPath('data.body_html', '<p><strong>查看最新消息</strong></p>');

        $this->getJson('/api/v1/content/announcements/latest')->assertOk()->assertJsonPath('data.title', '已发布公告');
        $this->getJson('/api/v1/content/announcements')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/admin/help-articles')->assertOk();
        $this->get('/admin/site-banners')->assertOk();
    }

    public function test_banner_accepts_a_hash_route_and_rejects_external_navigation(): void
    {
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '内容运营', 'permissions' => ['content.manage']]));
        $fields = ['name' => '活动', 'image_url' => 'https://assets.example.test/banner.png', 'active' => true, 'sort_order' => 1];
        app(Publishing::class)->banner(null, $fields + ['target_path' => '#/red-packets'], $operator);
        $this->getJson('/api/v1/content/banners')->assertOk()->assertJsonPath('data.0.target', '/red-packets');
        $this->expectException(ValidationException::class);
        app(Publishing::class)->banner(null, $fields + ['target_path' => '#//example.test'], $operator);
    }

    public function test_public_content_hides_drafts_and_unpublished_banner_targets(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '内容运营', 'permissions' => ['content.manage']]));
        $publishing = app(Publishing::class);
        $draft = $publishing->article('help', null, [
            'title' => '草稿', 'body' => '隐藏内容', 'published' => false, 'sort_order' => 1,
        ], $operator);

        $publishing->banner(null, [
            'name' => '帮助链接', 'image_url' => 'https://assets.example.test/banner.png',
            'active' => true, 'sort_order' => 1, 'target_type' => 'help_article', 'target_id' => $draft->id,
        ], $operator);
        $this->getJson("/api/v1/content/help/{$draft->id}")->assertNotFound();
        $this->getJson('/api/v1/content/banners')->assertOk()->assertJsonCount(0, 'data');

        $publishing->article('help', $draft, [
            'title' => '公开教程', 'body' => '查看积分', 'published' => true, 'sort_order' => 1,
        ], $operator);
        $this->getJson('/api/v1/content/banners')->assertOk()->assertJsonPath('data.0.target', "/help/{$draft->id}");
        $this->getJson('/api/v1/content/help')->assertOk()->assertJsonPath('data.0.title', '公开教程');

        try {
            $publishing->banner(null, [
                'name' => '外部地址', 'image_url' => 'https://assets.example.test/banner.png',
                'active' => true, 'sort_order' => 1, 'target_type' => '//untrusted.test/',
            ], $operator);
            $this->fail('Expected an invalid internal target error.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('target_type', $error->errors());
        }
    }
}
