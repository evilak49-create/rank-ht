<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSettings;
use App\Filament\Pages\SupportSettings;
use App\Filament\Resources\SupportFaqResource\Pages\ListSupportFaqs;
use App\Models\AdminRole;
use App\Models\SiteArticle;
use App\Models\SupportFaq;
use App\Models\User;
use App\SupportDesk\AiConfiguration;
use App\SupportDesk\CustomerSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_support_survives_ai_disable_and_only_published_answers_are_used(): void
    {
        $staff = $this->administrator();
        $this->getJson('/api/v1/support')->assertOk()->assertJsonPath('data.mode', 'human');
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))->push($this->reply('Use a página de vínculo.'))]);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        $support = app(CustomerSupport::class);
        $faq = $support->saveFaq(null, [
            'question' => 'Como vincular minha plataforma?', 'aliases' => ['ligar plataforma'],
            'answer' => 'Abra a página de vínculo.', 'published' => true,
        ], $staff);
        $support->saveFaq(null, ['question' => 'Segredo em rascunho', 'answer' => 'Draft', 'published' => false], $staff);
        $settings = [
            'ai_enabled' => true, 'welcome' => 'Olá', 'recommended_ids' => [$faq->id],
            'channels' => [
                ['name' => 'Suporte', 'url' => 'https://example.test/support', 'enabled' => true],
                ['name' => 'Hidden', 'contact' => 'disabled', 'enabled' => false],
            ],
        ];
        $support->saveSettings($settings, $staff);
        $this->getJson('/api/v1/support')->assertOk()->assertJsonPath('data.mode', 'chat')
            ->assertJsonPath('data.suggestions.0.id', $faq->id)->assertJsonCount(1, 'data.channels');
        $this->postJson('/api/v1/support/chat', ['question' => 'Segredo em rascunho'])->assertOk()
            ->assertJsonPath('data.type', 'human');
        $this->postJson('/api/v1/support/chat', ['question' => 'Qual é o saldo dos meus pontos?'])->assertOk()
            ->assertJsonPath('data.link', '/wallet');
        $this->postJson('/api/v1/support/chat', ['question' => 'ligar plataforma'])->assertOk()
            ->assertJsonPath('data.faq_id', $faq->id)->assertJsonPath('data.answer', 'Use a página de vínculo.');
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'Qual é o saldo') || str_contains($request->body(), 'Segredo em rascunho'));

        $support->saveSettings([...$settings, 'ai_enabled' => false], $staff);
        $this->getJson('/api/v1/support')->assertOk()->assertJsonPath('data.mode', 'human')
            ->assertJsonPath('data.channels.0.name', 'Suporte');
        $this->postJson('/api/v1/support/chat', ['question' => 'ligar plataforma'])->assertConflict();
        Http::assertSentCount(2);
    }

    public function test_invalid_ai_credentials_preserve_the_previously_verified_model(): void
    {
        $staff = $this->administrator();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()->push($this->reply('OK'))->pushStatus(401)]);
        $settings = app(AiConfiguration::class);
        $settings->save(['model' => 'deepseek-flash', 'api_key' => 'verified-secret'], $staff);
        try {
            $settings->save(['model' => 'deepseek-v4-pro', 'api_key' => 'bad-secret'], $staff);
            $this->fail('An unverified configuration must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('api_key', $exception->errors());
        }
        $this->assertSame('deepseek-flash', $settings->active()->model);
        $this->assertStringNotContainsString('verified-secret', DB::table('ai_settings')->sole()->api_key);
        $this->assertDatabaseHas('ai_setting_audits', ['status' => 'failed', 'model' => 'deepseek-v4-pro']);
    }

    public function test_admin_can_publish_a_faq_associate_help_configure_channels_and_preview(): void
    {
        $staff = $this->administrator();
        $this->actingAs($staff, 'web')->get('/admin/support-settings')->assertOk();
        $this->get('/admin/support-faqs')->assertOk();
        $this->get('/admin/ai-settings')->assertOk();
        $article = SiteArticle::create([
            'type' => 'help', 'title' => '积分教程', 'body' => '操作说明', 'sort_order' => 0,
            'published' => true, 'published_at' => now()->subMinute(), 'user_id' => $staff->id,
        ]);
        Livewire::test(ListSupportFaqs::class)->callAction('create_faq', [
            'question' => '怎么兑换积分', 'aliases' => ['兑换步骤'], 'answer' => '打开兑换页面。',
            'published' => true, 'help_article_id' => $article->id,
        ])->assertHasNoActionErrors();
        $faq = SupportFaq::query()->sole();
        Livewire::test(SupportSettings::class)
            ->set('question', '兑换步骤')->set('language', 'zh-CN')->call('testQuestion')
            ->assertSet('preview.help.link', '/help/'.$article->id)->assertSet('preview.answer', '打开兑换页面。');

        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()->push($this->reply('OK'))->pushStatus(503)]);
        Livewire::test(AiSettings::class)->fillForm(['model' => 'deepseek-flash', 'api_key' => 'admin-test-secret'])
            ->call('save')->assertHasNoFormErrors()->assertSet('data.api_key', null);
        Livewire::test(SupportSettings::class)->fillForm([
            'ai_enabled' => true, 'recommended_ids' => [$faq->id],
            'channels' => [['name' => '人工客服', 'contact' => 'support-account', 'enabled' => true]],
        ])->call('save')->assertHasNoFormErrors();
        $this->getJson('/api/v1/support?language=zh-CN')->assertOk()
            ->assertJsonPath('data.mode', 'chat')->assertJsonPath('data.channels.0.contact', 'support-account');
        $article->forceFill(['published' => false])->save();
        $this->postJson('/api/v1/support/chat', ['question' => '兑换步骤', 'language' => 'zh-CN'])
            ->assertOk()->assertJsonPath('data.answer', '打开兑换页面。')->assertJsonPath('data.help', null);
    }

    private function administrator(): User
    {
        $staff = User::factory()->create();
        $staff->forceFill(['panel_enabled' => true])->save();
        $staff->roles()->attach(AdminRole::create(['name' => 'Support', 'permissions' => ['support.manage', 'system.manage-ai']]));

        return $staff;
    }

    private function reply(string $content): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]]];
    }
}
