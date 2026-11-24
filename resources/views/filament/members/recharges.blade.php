<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $accountLabel }}</x-slot>
        <x-slot name="description">合作平台最近一个月的充值记录</x-slot>

        @if ($queryError)
            <p role="status">{{ $queryError }}</p>
            <x-filament::button wire:click="loadPage(1)" wire:loading.attr="disabled" class="mt-4">重新查询</x-filament::button>
        @elseif (empty($recharges['data']))
            <p>最近一个月暂无充值记录。</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr>
                            <th class="p-3 text-start">平台充值单号</th>
                            <th class="p-3 text-start">充值金额</th>
                            <th class="p-3 text-start">充值时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recharges['data'] as $entry)
                            <tr>
                                <td class="p-3">{{ $entry['reference'] }}</td>
                                <td class="p-3">R$ {{ \App\Points\PointRules::display($entry['amount_cents'], 2) }}</td>
                                <td class="p-3">{{ \Carbon\CarbonImmutable::parse($entry['recharged_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button wire:click="loadPage({{ $recharges['current_page'] - 1 }})" wire:loading.attr="disabled" :disabled="$recharges['current_page'] <= 1">上一页</x-filament::button>
                <span>第 {{ $recharges['current_page'] }} 页，共 {{ $recharges['last_page'] }} 页</span>
                <x-filament::button wire:click="loadPage({{ $recharges['current_page'] + 1 }})" wire:loading.attr="disabled" :disabled="! $recharges['has_more']">下一页</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
