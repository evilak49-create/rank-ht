<?php

namespace Tests\Feature;

use App\Filament\Resources\SupportConversationResource\Pages\ViewSupportConversation;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\SupportConversation;
use App\Models\User;
use App\SupportDesk\AiConfiguration;
use App\SupportDesk\CustomerSupport;
use App\SupportDesk\SupportConversations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SupportConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_leave_a_message_for_staff_when_ai_is_not_configured(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/api/v1/support/chat', ['question' => '需要人工帮助', 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data', null)->assertJsonPath('conversation.messages.0.sender', 'visitor');
        $conversation = SupportConversation::query()->sole();
        $operator = $this->operator(true);
        $support = app(SupportConversations::class);
        $support->takeOver($conversation, $operator);
        $support->sendStaffReply($conversation, $operator, '你好，请问有什么可以帮您？');
        $this->getJson('/api/v1/support/conversation')->assertOk()
            ->assertJsonPath('data.mode', 'human')->assertJsonPath('data.messages.1.sender', 'staff');
        Http::assertNothingSent();
    }

    public function test_guest_messages_persist_and_staff_can_take_over_and_release(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->answer('OK'))->push($this->answer('AI 回复'))->push($this->answer('AI 已恢复'))]);
        $staff = $this->operator(true);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        app(CustomerSupport::class)->saveSettings(['ai_enabled' => true], $staff);

        $requestId = (string) Str::uuid();
        $response = $this->postJson('/api/v1/support/chat', ['question' => '第一句', 'request_id' => $requestId])
            ->assertOk()->assertJsonPath('data.answer', 'AI 回复');
        $conversation = SupportConversation::query()->sole();
        $this->assertNull($conversation->member_id);
        $this->assertSame($conversation->public_id, $response->json('conversation.id'));

        $this->postJson('/api/v1/support/chat', ['question' => '第一句', 'request_id' => $requestId])->assertOk();
        $this->postJson('/api/v1/support/chat', ['question' => '变更后的文本', 'request_id' => $requestId])->assertConflict();
        $this->getJson('/api/v1/support/conversation')->assertOk()->assertJsonCount(2, 'data.messages');

        $support = app(SupportConversations::class);
        $support->takeOver($conversation, $staff);
        $this->postJson('/api/v1/support/chat', ['question' => '人工问题', 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data', null)->assertJsonPath('conversation.mode', 'human');
        $anotherOperator = $this->operator(true);
        $this->assertHttpStatus(409, fn () => $support->takeOver($conversation, $anotherOperator));
        $support->sendStaffReply($conversation, $staff, '人工回复');

        $this->getJson('/api/v1/support/conversation?after='.$response->json('conversation.messages.1.id'))->assertOk()
            ->assertJsonPath('data.messages.1.body', '人工回复')->assertJsonPath('data.messages.1.sender', 'staff')
            ->assertJsonMissingPath('data.messages.1.staff_id');
        $support->release($conversation, $staff);
        $this->postJson('/api/v1/support/chat', ['question' => '恢复后问题', 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.answer', 'AI 已恢复')->assertJsonPath('conversation.mode', 'ai');
        Http::assertSentCount(3);
    }

    public function test_guest_and_member_are_isolated_and_staff_permission_is_checked(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->answer('OK'))->push($this->answer('AI 回复'))->push($this->answer('会员 AI 回复'))]);
        $staff = $this->operator(true);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        app(CustomerSupport::class)->saveSettings(['ai_enabled' => true], $staff);
        $this->postJson('/api/v1/support/chat', ['question' => '私密来访内容', 'request_id' => (string) Str::uuid()])->assertOk();
        $guestConversation = SupportConversation::query()->sole();
        $this->withSession(['support_guest_key' => Str::random(48)])
            ->getJson('/api/v1/support/conversation?id='.$guestConversation->public_id)->assertOk()
            ->assertJsonPath('data.id', null)->assertJsonCount(0, 'data.messages');

        $member = Member::create(['phone' => '11912345678', 'password' => 'test-only-password']);
        $member->refresh();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => $member->session_version]);
        $this->getJson('/api/v1/support/conversation?id='.$guestConversation->public_id)->assertOk()
            ->assertJsonPath('data.id', null)->assertJsonCount(0, 'data.messages');
        $this->postJson('/api/v1/support/chat', ['question' => '会员提问', 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.answer', '会员 AI 回复');
        $this->assertDatabaseHas('support_conversations', ['member_id' => $member->id]);
        Auth::guard('member')->logout();

        $unprivileged = $this->operator(false);
        $this->assertHttpStatus(403, fn () => app(SupportConversations::class)->takeOver($guestConversation, $unprivileged));
        $this->actingAs($unprivileged, 'web')->get('/admin/support-conversations')->assertForbidden();
        $this->actingAs($staff, 'web')->get('/admin/support-conversations')->assertOk()->assertSee($guestConversation->public_id);
        Livewire::test(ViewSupportConversation::class, ['record' => $guestConversation->getKey()])
            ->assertSee('私密来访内容')->call('takeOver')->set('reply', '你好，人工客服为您服务。')->call('sendReply')
            ->assertSee('你好，人工客服为您服务。')->call('release');
    }

    public function test_model_fallback_has_exact_text_link_and_keeps_ai_mode(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->answer('OK'))->push($this->answer('SUPPORT_HANDOFF_REQUIRED'))]);
        $staff = $this->operator(true);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        app(CustomerSupport::class)->saveSettings(['ai_enabled' => true,
            'channels' => [['name' => '高级客服经理', 'url' => 'https://example.test/support', 'enabled' => true]],
        ], $staff);

        $this->postJson('/api/v1/support/chat', ['question' => '超出知识范围的问题', 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.answer', '当前问题请咨询高级客服经理：')
            ->assertJsonPath('data.channels.0.url', 'https://example.test/support')
            ->assertJsonPath('conversation.mode', 'ai');
    }

    public function test_in_flight_model_reply_is_ignored_after_staff_takes_over_and_releases(): void
    {
        Http::preventStrayRequests();
        $staff = $this->operator(true);
        $requests = 0;
        Http::fake(['https://api.deepseek.com/chat/completions' => function () use ($staff, &$requests) {
            if (++$requests === 1) {
                return Http::response($this->answer('OK'));
            }

            $conversation = SupportConversation::query()->sole();
            app(SupportConversations::class)->takeOver($conversation, $staff);
            app(SupportConversations::class)->sendStaffReply($conversation, $staff, '以人工回复为准');
            app(SupportConversations::class)->release($conversation, $staff);

            return Http::response($this->answer('迟到的 AI 回复'));
        }]);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);
        app(CustomerSupport::class)->saveSettings(['ai_enabled' => true], $staff);
        $this->postJson('/api/v1/support/chat', ['question' => '原问题', 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data', null)->assertJsonCount(2, 'conversation.messages')
            ->assertJsonPath('conversation.messages.1.body', '以人工回复为准');
    }

    private function assertHttpStatus(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected an HTTP exception.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function operator(bool $canManage): User
    {
        $staff = User::factory()->create();
        $staff->forceFill(['panel_enabled' => true])->save();
        $staff->roles()->attach(AdminRole::create([
            'name' => 'Support '.$staff->id,
            'permissions' => $canManage ? ['support.manage', 'system.manage-ai'] : [],
        ]));

        return $staff;
    }

    private function answer(string $text): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]]];
    }
}
