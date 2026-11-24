<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr><th>时间</th><th>类型</th><th>金额</th><th>红包余额</th><th>冻结金额</th></tr></thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry->created_at }}</td>
                    <td>{{ ['initial' => '初始红包', 'invite' => '邀请奖励', 'frozen' => '提现冻结', 'released' => '提现退回', 'paid' => '提现到账', 'rejected' => '拒绝扣款'][$entry->kind] ?? $entry->kind }}</td>
                    <td>R$ {{ \App\Points\PointRules::display($entry->amount_cents, 2) }}</td>
                    <td>R$ {{ \App\Points\PointRules::display($entry->balance_after, 2) }}</td>
                    <td>R$ {{ \App\Points\PointRules::display($entry->frozen_after, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">暂无流水</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
