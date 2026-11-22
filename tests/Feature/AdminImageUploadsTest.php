<?php

namespace Tests\Feature;

use App\Auth\AdminAccountCreator;
use App\Content\Publishing;
use App\Filament\Resources\HelpArticleResource\Pages\ListHelpArticles;
use App\Filament\Resources\SiteBannerResource\Pages\ListSiteBanners;
use App\Models\AdminRole;
use App\Models\Product;
use App\Models\SiteArticle;
use App\Models\SiteBanner;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use App\Shop\PhysicalShop;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AdminImageUploadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_image_slots_support_local_uploads_without_oss(): void
    {
        $disk = Storage::fake('site-images');
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create(['name' => '运营', 'permissions' => [
            'content.manage', 'platforms.edit', 'shop.manage-products',
        ]]));
        $platform = app(PlatformAdministration::class)->create([
            'name' => 'Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.0', 'image_upload' => $this->image(),
        ], $operator);
        $banner = app(Publishing::class)->banner(null, [
            'name' => 'Banner', 'image_upload' => $this->image(), 'active' => true, 'sort_order' => 0, 'target_type' => 'home',
        ], $operator);
        app(PhysicalShop::class)->saveProduct(null, ['name' => 'Product', 'points_price' => 10, 'is_active' => true, 'image_upload' => $this->image()], $operator);
        $help = app(Publishing::class)->article('help', null, [
            'title' => 'Help', 'body' => 'Body', 'published' => true,
            'steps' => [['title' => 'First', 'body' => 'Body', 'image_upload' => $this->image()]],
        ], $operator);

        foreach ([$platform->logo_url, $banner->image_url, Product::query()->sole()->image_url, $help->steps[0]['image_url']] as $url) {
            $this->assertStringStartsWith('/uploads/images/', $url);
            $disk->assertExists(basename($url));
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        $this->assertCount(4, $disk->allFiles());
        $this->get('/uploads/images/not-an-image.php')->assertNotFound();
        try {
            app(Publishing::class)->banner(null, [
                'name' => 'Invalid', 'image_upload' => $this->image(), 'active' => true, 'sort_order' => 0, 'target_type' => 'unknown',
            ], $operator);
            $this->fail('Expected the invalid banner target to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_type', $exception->errors());
        }
        $this->assertCount(4, $disk->allFiles());
    }

    public function test_admin_forms_upload_banner_and_nested_help_image_and_menu_groups_are_ordered(): void
    {
        Storage::fake('site-images');
        app(AdminAccountCreator::class)->create('admin-uploads', 'Admin', 'password');
        $this->actingAs(User::query()->where('username', 'admin-uploads')->sole(), 'web');
        $this->get('/admin')->assertOk()->assertSeeInOrder([
            '运营总览', '会员与活动', '平台与保障', '积分与配置', '内容与客服', '系统设置',
        ]);

        Livewire::test(ListSiteBanners::class)->mountAction('create_banner')
            ->setActionData(['name' => 'Banner', 'active' => true, 'sort_order' => 0, 'target_type' => 'home'])
            ->set('mountedActions.0.data.image_upload', [$this->image()])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertStringStartsWith('/uploads/images/', SiteBanner::query()->sole()->image_url);

        $form = Livewire::test(ListHelpArticles::class)->mountAction('create_article')
            ->setActionData([
                'title' => 'Help', 'body' => 'Body', 'published' => true, 'sort_order' => 0,
                'steps' => [['title' => 'First', 'body' => 'Details']],
            ]);
        $step = array_key_first($form->get('mountedActions.0.data.steps'));
        $form->set("mountedActions.0.data.steps.$step.image_upload", [$this->image()])
            ->callMountedAction()->assertHasNoActionErrors();
        $article = SiteArticle::query()->sole();
        $this->getJson('/api/v1/content/help/'.$article->id)->assertOk()
            ->assertJsonPath('data.steps.0.image_url', $article->steps[0]['image_url']);
        $this->assertStringStartsWith('/uploads/images/', $article->steps[0]['image_url']);
        Livewire::test(ListHelpArticles::class)->mountAction(TestAction::make('edit_article')->table($article))
            ->set('mountedActions.0.data.title', 'Updated')
            ->callMountedAction()->assertHasNoActionErrors();
        $this->getJson('/api/v1/content/help/'.$article->id)->assertOk()
            ->assertJsonPath('data.title', 'Updated')->assertJsonPath('data.steps.0.image_url', $article->steps[0]['image_url']);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('example.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+bWZkAAAAASUVORK5CYII=',
        ));
    }
}
