<x-filament-panels::page>
    <div class="flex items-center gap-3">
        <img src="/api/v1/pwa/icons/apple.png?v={{ app(\App\Pwa\PwaConfiguration::class)->icon() }}"
            width="56" height="56" class="rounded-xl" alt="当前桌面图标">
        <span>{{ $data['short_name'] ?? 'QVIP' }}</span>
    </div>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" wire:loading.attr="disabled">保存配置</x-filament::button>
    </form>
</x-filament-panels::page>
