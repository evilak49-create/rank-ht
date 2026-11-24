<?php

namespace App\SupportDesk;

use App\Models\Member;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportConversations
{
    private const PAGE_SIZE = 75;

    public function __construct(private readonly CustomerSupport $support) {}

    public function forVisitor(Request $request, bool $create = false): ?SupportConversation
    {
        $guard = Auth::guard('member');
        $member = $guard->user();
        if ($member instanceof Member) {
            $member->refresh();
            if ($member->disabled_at !== null || $request->session()->get('member_session_version') !== $member->session_version) {
                $guard->logout();
                $request->session()->forget('member_session_version');
                $request->session()->regenerate(true);
                abort(401, $member->disabled_at !== null ? '会员账号已禁用。' : '请重新登录。');
            }
            $owner = ['member_id' => $member->getKey()];
        } else {
            $key = $request->session()->get('support_guest_key');
            if (! is_string($key)) {
                $key = Str::random(48);
                $request->session()->put('support_guest_key', $key);
            }
            $owner = ['guest_key' => hash('sha256', $key)];
        }

        return $create
            ? SupportConversation::query()->firstOrCreate($owner, ['public_id' => (string) Str::uuid()])
            : SupportConversation::query()->where($owner)->first();
    }

    public function visitorData(?SupportConversation $conversation, ?int $after = null, ?int $before = null): array
    {
        if ($conversation === null) {
            return ['id' => null, 'mode' => 'ai', 'messages' => [], 'has_earlier' => false];
        }

        $conversation->refresh();
        $query = $conversation->messages()->where('sender', '!=', 'event')
            ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before));
        $ordered = $after === null ? $query->orderByDesc('id') : $query->orderBy('id');
        $batch = $ordered->limit(self::PAGE_SIZE + 1)->get();
        $items = $batch->take(self::PAGE_SIZE);
        if ($after === null) {
            $items = $items->reverse();
        }

        return [
            'id' => $conversation->public_id,
            'mode' => $conversation->operator_id === null ? 'ai' : 'human',
            'messages' => $items->map(fn (SupportMessage $message): array => [
                'id' => $message->id, 'sender' => $message->sender, 'body' => $message->body,
                'request_id' => $message->request_id, 'reply' => $message->reply,
                'created_at' => $message->created_at->toISOString(),
            ])->values(),
            ...($after === null ? ['has_earlier' => $batch->count() > self::PAGE_SIZE] : []),
        ];
    }

    public function send(SupportConversation $conversation, string $question, string $language, ?string $requestId, array $legacyHistory): ?array
    {
        [$message, $generate, $version] = DB::transaction(function () use ($conversation, $question, $requestId): array {
            $locked = $this->lock($conversation);
            if ($requestId !== null) {
                $existing = $locked->messages()->where('request_id', $requestId)->first();
                if ($existing !== null) {
                    abort_unless($existing->body === $question, 409, '该消息已提交。');

                    return [$existing, false, $locked->mode_version];
                }
            }
            $aiMode = $locked->operator_id === null;
            $generate = $aiMode && $this->support->aiAvailable();
            abort_if($aiMode && ! $generate && $requestId === null, 409, 'AI 客服已停用。');
            $message = $this->append($locked, 'visitor', $question, ['request_id' => $requestId]);

            return [$message, $generate, $locked->mode_version];
        }, 3);

        if (! $generate) {
            return $conversation->messages()->where('reply_to_id', $message->id)->first()?->reply;
        }

        $history = $requestId === null ? $legacyHistory : $this->aiHistory($conversation, $message);
        $reply = $this->support->ask($question, $language, $history, requireEnabled: false);

        return DB::transaction(function () use ($conversation, $message, $version, $reply): ?array {
            $locked = $this->lock($conversation);
            if ($locked->operator_id !== null || $locked->mode_version !== $version) {
                return null;
            }
            $this->append($locked, 'ai', (string) $reply['answer'], ['reply_to_id' => $message->id, 'reply' => $reply]);

            return $reply;
        }, 3);
    }

    public function takeOver(SupportConversation $conversation, User $operator): void
    {
        $this->authorize($operator);
        DB::transaction(function () use ($conversation, $operator): void {
            $locked = $this->lock($conversation);
            if ($locked->operator_id === $operator->id) {
                return;
            }
            abort_if($locked->operator?->hasPermission('support.manage'), 409, '该会话已由其他客服接管。');
            $locked->forceFill(['operator_id' => $operator->id, 'mode_version' => $locked->mode_version + 1])->save();
            $this->append($locked, 'event', '接管会话', ['operator_id' => $operator->id]);
        }, 3);
    }

    public function release(SupportConversation $conversation, User $operator): void
    {
        $this->authorize($operator);
        DB::transaction(function () use ($conversation, $operator): void {
            $locked = $this->lock($conversation);
            abort_unless($locked->operator_id === $operator->id, 409, '请先接管该会话。');
            $locked->forceFill(['operator_id' => null, 'mode_version' => $locked->mode_version + 1])->save();
            $this->append($locked, 'event', '结束接待', ['operator_id' => $operator->id]);
        }, 3);
    }

    public function sendStaffReply(SupportConversation $conversation, User $operator, string $body): void
    {
        $this->authorize($operator);
        $body = validator(['body' => trim($body)], ['body' => 'required|string|max:2000'])->validate()['body'];
        DB::transaction(function () use ($conversation, $operator, $body): void {
            $locked = $this->lock($conversation);
            abort_unless($locked->operator_id === $operator->id, 409, '请先接管该会话。');
            $this->append($locked, 'staff', $body, ['operator_id' => $operator->id]);
        }, 3);
    }

    public function staffMessages(SupportConversation $conversation, User $operator, int $limit, ?int $before = null): Collection
    {
        $this->authorize($operator);

        return $conversation->messages()->with('operator:id,username,name')
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->latest('id')->limit($limit)->get();
    }

    private function aiHistory(SupportConversation $conversation, SupportMessage $message): array
    {
        return SupportMessage::query()->from('support_messages as replies')
            ->join('support_messages as questions', 'questions.id', '=', 'replies.reply_to_id')
            ->where('replies.conversation_id', $conversation->id)
            ->where('questions.id', '<', $message->id)
            ->where('replies.sender', 'ai')->orderByDesc('questions.id')->limit(6)
            ->get(['questions.body as question', 'replies.body as answer'])
            ->reverse()->map(fn (SupportMessage $turn): array => [
                'question' => $turn->question,
                'answer' => mb_substr($turn->answer, 0, 1400),
            ])->values()->all();
    }

    private function append(SupportConversation $conversation, string $sender, string $body, array $extra): SupportMessage
    {
        $message = $conversation->messages()->create(['sender' => $sender, 'body' => $body] + $extra);
        if ($sender !== 'event') {
            $conversation->forceFill([
                'last_message' => mb_substr($body, 0, 500), 'last_message_at' => $message->created_at,
            ])->save();
        }

        return $message;
    }

    private function lock(SupportConversation $conversation): SupportConversation
    {
        return SupportConversation::query()->lockForUpdate()->findOrFail($conversation->id);
    }

    private function authorize(User $operator): void
    {
        abort_unless($operator->hasPermission('support.manage'), 403);
    }
}
