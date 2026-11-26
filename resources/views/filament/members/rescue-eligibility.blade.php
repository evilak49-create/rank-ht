<dl class="space-y-3">
    <div><dt class="font-medium">核对结果</dt><dd>{{ $assessment['result'] }}</dd></div>
    <div><dt class="font-medium">基线时间</dt><dd>{{ $assessment['baseline_at'] ?? '未建立' }}</dd></div>
    @foreach (['deposit_cents' => '本期充值', 'net_loss_cents' => '本期净亏损', 'amount_cents' => '预计可领'] as $key => $label)
        @if (isset($assessment[$key]))
            <div><dt class="font-medium">{{ $label }}</dt><dd>R$ {{ \App\Points\PointRules::display($assessment[$key], 2) }}</dd></div>
        @endif
    @endforeach
    @isset($assessment['wager_multiplier'])
        <div><dt class="font-medium">本期实际打码倍数</dt><dd>{{ $assessment['wager_multiplier'] }} 倍</dd></div>
    @endisset
</dl>
