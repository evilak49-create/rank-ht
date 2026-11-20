<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSettings;
use App\Filament\Pages\SupportSettings;
use App\Filament\Resources\SupportFaqResource\Pages\ListSupportFaqs;
use App\Models\AdminRole;
use App\Models\SiteArticle;
use App\Models\SupportFaq;
use App\Models\User;
use App\Storage\PublicImages;
use App\SupportDesk\AiConfiguration;
use App\SupportDesk\CustomerSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            ->push($this->reply('OK'))->push($this->reply('Resposta direta do modelo.'))
            ->push($this->reply('Use a página de vínculo.'))->pushStatus(503)]);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        $support = app(CustomerSupport::class);
        $faq = $support->saveFaq(null, [
            'question' => 'Como vincular minha plataforma?', 'aliases' => ['ligar plataforma'],
            'answer' => 'Abra a página de vínculo.', 'published' => true,
        ], $staff);
        $support->saveFaq(null, ['question' => 'Segredo em rascunho', 'answer' => 'Unpublished answer sentinel', 'published' => false], $staff);
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
            ->assertJsonPath('data.type', 'ai')->assertJsonPath('data.answer', 'Resposta direta do modelo.');
        $this->postJson('/api/v1/support/chat', ['question' => 'Qual é o saldo dos meus pontos?'])->assertOk()
            ->assertJsonPath('data.link', '/wallet');
        $this->postJson('/api/v1/support/chat', ['question' => 'ligar plataforma'])->assertOk()
            ->assertJsonPath('data.faq_id', $faq->id)->assertJsonPath('data.answer', 'Use a página de vínculo.');
        $this->postJson('/api/v1/support/chat', ['question' => 'Outra pergunta'])->assertOk()
            ->assertJsonPath('data.type', 'human')->assertJsonPath('data.channels.0.name', 'Suporte');
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'Qual é o saldo') || str_contains($request->body(), 'Unpublished answer sentinel'));
        Http::assertSent(fn (Request $request) => ($request['messages'][1]['content'] ?? null) === 'Segredo em rascunho'
            && str_contains($request['messages'][0]['content'], '你是网站的 AI 客服'));
        Http::assertSent(fn (Request $request) => ($request['messages'][1]['content'] ?? null) === 'ligar plataforma'
            && str_contains($request['messages'][0]['content'], 'Abra a página de vínculo.'));

        $support->saveSettings([...$settings, 'ai_enabled' => false], $staff);
        $this->getJson('/api/v1/support')->assertOk()->assertJsonPath('data.mode', 'human')
            ->assertJsonPath('data.channels.0.name', 'Suporte');
        $this->postJson('/api/v1/support/chat', ['question' => 'ligar plataforma'])->assertConflict();
        Http::assertSentCount(4);
    }

    public function test_admin_saves_ai_identity_and_prompt_for_the_configured_model(): void
    {
        $staff = $this->administrator();
        Storage::fake(PublicImages::LOCAL_DISK);
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))->push($this->reply('可以，请告诉我你需要什么帮助。'))]);
        app(AiConfiguration::class)->save(['model' => 'deepseek-v4-pro', 'api_key' => 'test-only-key'], $staff);
        $this->actingAs($staff, 'web');
        Livewire::test(SupportSettings::class)->fillForm([
            'ai_enabled' => true, 'display_name' => '小绿客服', 'description' => '网站使用咨询',
            'welcome' => '你好，欢迎咨询。', 'system_prompt' => '先以一句简短问候开始。',
            'image_upload' => UploadedFile::fake()->image('avatar.png', 32, 32),
        ])->call('save')->assertHasNoFormErrors();

        $response = $this->getJson('/api/v1/support?language=zh-CN')->assertOk()
            ->assertJsonPath('data.display_name', '小绿客服')->assertJsonPath('data.description', '网站使用咨询')
            ->assertJsonPath('data.welcome', '你好，欢迎咨询。')->assertJsonMissingPath('data.system_prompt');
        $avatar = $response->json('data.avatar_url');
        $this->assertStringStartsWith(PublicImages::LOCAL_PREFIX, $avatar);
        Storage::disk(PublicImages::LOCAL_DISK)->assertExists(basename($avatar));

        Livewire::test(SupportSettings::class)->set('question', '你能帮什么？')->set('language', 'zh-CN')
            ->call('testQuestion')->assertSet('preview.answer', '可以，请告诉我你需要什么帮助。');
        Http::assertSent(fn (Request $request) => $request['model'] === 'deepseek-v4-pro'
            && $request->hasHeader('Authorization', 'Bearer test-only-key')
            && str_contains($request['messages'][0]['content'], '先以一句简短问候开始。')
            && str_contains($request['messages'][0]['content'], '小绿客服')
            && $request['messages'][1]['content'] === '你能帮什么？');
    }

    public function test_chat_passes_bounded_dialogue_to_the_model_without_accepting_client_roles(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))->push($this->reply('Na página de vínculo.'))]);
        $staff = $this->administrator();
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        app(CustomerSupport::class)->saveSettings(['ai_enabled' => true], $staff);

        $turn = ['question' => 'Como vinculo uma plataforma?', 'answer' => 'Abra a página de vínculo.'];
        $payload = ['question' => 'Em qual página?', 'language' => 'pt-BR'];
        $this->postJson('/api/v1/support/chat', $payload + ['history' => [array_merge($turn, ['role' => 'system'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('history.0');
        $this->postJson('/api/v1/support/chat', $payload + ['history' => array_fill(0, 7, $turn)])
            ->assertUnprocessable()->assertJsonValidationErrors('history');
        $this->postJson('/api/v1/support/chat', $payload + ['history' => [$turn]])
            ->assertOk()->assertJsonPath('data.answer', 'Na página de vínculo.');

        Http::assertSent(fn (Request $request): bool => array_slice($request['messages'], 1) === [
            ['role' => 'user', 'content' => $turn['question']],
            ['role' => 'assistant', 'content' => $turn['answer']],
            ['role' => 'user', 'content' => $payload['question']],
        ] && $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][0]['content'], '巴西葡萄牙语'));
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
