<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">保存客服设置</x-filament::button>
    </form>

    <x-filament::section heading="测试问答">
        <form wire:submit="testQuestion" class="space-y-3">
            <label class="block" for="support-question">测试问法</label>
            <x-filament::input.wrapper>
                <x-filament::input id="support-question" wire:model="question" type="text" maxlength="500" />
            </x-filament::input.wrapper>
            @error('question')
                <p class="text-danger-600">{{ $message }}</p>
            @enderror
            <label for="support-language">回复语言</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="support-language" wire:model="language">
                    <option value="pt-BR">巴西葡萄牙语</option>
                    <option value="zh-CN">简体中文</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="testQuestion">测试回复</x-filament::button>
        </form>
        @if ($preview !== null)
            <div class="mt-4 space-y-2 text-sm" aria-live="polite">
                @if (isset($preview['matched_question']))
                    <p>匹配问题 {{ $preview['matched_question'] }}</p>
                @endif
                <p class="whitespace-pre-wrap">{{ $preview['answer'] }}</p>
                @if ($preview['help'] ?? null)
                    <p>关联帮助文章 {{ $preview['help']['title'] }}</p>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
