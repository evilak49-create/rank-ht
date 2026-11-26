<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $accountLabel }}</x-slot>
        <x-slot name="description">最近一个月的充值记录</x-slot>
        <x-slot name="afterHeader">
            <x-filament::badge :color="$queryError ? 'warning' : 'success'">{{ $queryError ? '本地记录' : '平台实时记录' }}</x-filament::badge>
        </x-slot>

        @if ($queryError)
            <div class="member-recharges-notice" role="status">
                <div>
                    <strong>{{ $queryError }}</strong>
                    <p>下方展示已同步的记录，可能缺少平台最新充值。</p>
                </div>
                <x-filament::button wire:click="loadPage(1)" wire:loading.attr="disabled" size="sm" color="gray">重新查询</x-filament::button>
            </div>
        @endif

        @if (empty($recharges['data']))
            <div class="member-recharges-empty">{{ $queryError ? '最近一个月暂无已同步的充值记录。' : '最近一个月暂无充值记录。' }}</div>
        @else
            <div class="member-recharges-table">
                <table>
                    <thead>
                        <tr><th scope="col">平台充值单号</th><th scope="col">充值时间</th><th scope="col">充值金额</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recharges['data'] as $entry)
                            @php($rechargedAt = \Carbon\CarbonImmutable::parse($entry['recharged_at'])->setTimezone(config('app.timezone')))
                            <tr>
                                <td class="member-recharges-reference">{{ $entry['reference'] }}</td>
                                <td><time datetime="{{ $entry['recharged_at'] }}">{{ $rechargedAt->format('Y-m-d H:i T') }}</time></td>
                                <td class="member-recharges-amount">R$ {{ \App\Points\PointRules::display($entry['amount_cents'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($recharges['last_page'] > 1)
            <nav class="member-recharges-pagination" aria-label="充值记录分页">
                <span>第 {{ $recharges['current_page'] }} 页，共 {{ $recharges['last_page'] }} 页</span>
                <div>
                    <x-filament::button wire:click="loadPage({{ $recharges['current_page'] - 1 }})" wire:loading.attr="disabled" :disabled="$recharges['current_page'] <= 1" size="sm" color="gray">上一页</x-filament::button>
                    <x-filament::button wire:click="loadPage({{ $recharges['current_page'] + 1 }})" wire:loading.attr="disabled" :disabled="! $recharges['has_more']" size="sm" color="gray">下一页</x-filament::button>
                </div>
            </nav>
        @endif
    </x-filament::section>

    <style>
        .member-recharges-notice {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 0.75rem; margin-bottom: 1rem; padding: 0.9rem 1rem;
            border: 1px solid rgb(202 138 4 / 30%); border-radius: 0.75rem;
            background: rgb(234 179 8 / 9%);
        }

        .member-recharges-notice p { margin-top: 0.25rem; font-size: 0.875rem; }
        .member-recharges-table { overflow-x: auto; border-radius: 0.75rem; border: 1px solid rgb(128 128 128 / 20%); }
        .member-recharges-table table { width: 100%; min-width: 32rem; border-collapse: collapse; font-size: 0.875rem; }
        .member-recharges-table th,
        .member-recharges-table td { padding: 0.8rem 1rem; text-align: start; }
        .member-recharges-table th { background: rgb(128 128 128 / 9%); font-weight: 600; }
        .member-recharges-table td { border-top: 1px solid rgb(128 128 128 / 17%); }
        .member-recharges-table tbody tr:hover { background: rgb(128 128 128 / 6%); }
        .member-recharges-reference { overflow-wrap: anywhere; }
        .member-recharges-table time { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .member-recharges-table th:last-child,
        .member-recharges-table .member-recharges-amount { text-align: end; white-space: nowrap; }
        .member-recharges-amount { font-weight: 650; font-variant-numeric: tabular-nums; color: #047857; }
        .dark .member-recharges-amount { color: #6ee7b7; }
        .member-recharges-empty { padding: 2rem 1rem; text-align: center; border: 1px dashed rgb(128 128 128 / 35%); border-radius: 0.75rem; }
        .member-recharges-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-top: 1rem; gap: 0.75rem; font-size: 0.875rem; }
        .member-recharges-pagination > div { display: flex; gap: 0.5rem; }
    </style>
</x-filament-panels::page>
