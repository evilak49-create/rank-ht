<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
            验证并保存
        </x-filament::button>
    </form>

    @if ($this->audit !== [])
        <x-filament::section heading="最近的配置验证">
            <ul class="space-y-2 text-sm">
                @foreach ($this->audit as $attempt)
                    <li>{{ $attempt->created_at }} · {{ $attempt->purpose === 'public' ? '公开图片' : '私有凭证' }} · {{ $attempt->provider === 'aws' ? 'Amazon S3' : '阿里云 OSS' }} · {{ $attempt->status === 'activated' ? '已生效' : '验证失败' }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
