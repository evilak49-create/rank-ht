<x-filament-panels::page>
    <div wire:poll.visible.5s class="space-y-5">
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="font-semibold">{{ $thread->member?->phone ?? '游客' }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $thread->public_id }}</div>
                    @if ($thread->operator)
                        <div class="text-sm">{{ $thread->operator->username ?: $thread->operator->name }} · 人工接待</div>
                    @else
                        <div class="text-sm">未接管</div>
                    @endif
                </div>
                @if ($ownedByOperator)
                    <x-filament::button color="gray" wire:click="release" wire:loading.attr="disabled" wire:target="release">
                        结束接待
                    </x-filament::button>
                @elseif ($canTakeOver)
                    <x-filament::button wire:click="takeOver" wire:loading.attr="disabled" wire:target="takeOver">
                        接管会话
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">对话记录</x-slot>
            @if ($hasEarlier || $before)
                <div class="mb-4 flex flex-wrap gap-2">
                    @if ($hasEarlier)
                        <x-filament::button color="gray" wire:click="earlier">更早的消息</x-filament::button>
                    @endif
                    @if ($before)
                        <x-filament::button color="gray" wire:click="latestMessages">返回最新消息</x-filament::button>
                    @endif
                </div>
            @endif
            <ol class="space-y-3">
                @foreach ($messages as $message)
                    <li wire:key="support-message-{{ $message->id }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="mb-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ match ($message->sender) {
                                'visitor' => $thread->member_id ? '会员' : '游客',
                                'ai' => 'AI 客服',
                                'staff' => $message->operator?->username ?: ($message->operator?->name ?? '人工客服'),
                                default => $message->operator?->username ?: ($message->operator?->name ?? '系统'),
                            } }} · <time datetime="{{ $message->created_at->toISOString() }}">{{ $message->created_at->format('Y-m-d H:i:s') }}</time>
                        </div>
                        <p class="whitespace-pre-wrap break-words">{{ $message->body }}</p>
                        @foreach ($message->reply['channels'] ?? [] as $channel)
                            @if ($channel['url'])
                                <a class="block text-sm underline" href="{{ $channel['url'] }}" rel="noopener noreferrer" target="_blank">{{ $channel['name'] }}</a>
                            @endif
                        @endforeach
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        @if ($ownedByOperator)
            <form wire:submit="sendReply" class="space-y-3">
                <label for="support-reply" class="block font-medium">人工回复</label>
                <x-filament::input.wrapper>
                    <textarea id="support-reply" wire:model="reply" rows="3" maxlength="2000"
                        class="block w-full rounded-lg border-0 bg-transparent px-3 py-2 text-sm focus:ring-0"></textarea>
                </x-filament::input.wrapper>
                @error('reply') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                @error('body') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sendReply">发送回复</x-filament::button>
            </form>
        @endif
    </div>
</x-filament-panels::page>
