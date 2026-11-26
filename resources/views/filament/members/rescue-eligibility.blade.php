@php
    $result = $assessment['result'];
    $amount = $assessment['amount_cents'] ?? null;
    $amountLabel = $amount === null ? '—' : 'R$ '.\App\Points\PointRules::display($amount, 2);
    $baseline = $assessment['baseline_at'] ?? null;
    $statusColor = match ($result) {
        '可领取' => 'success',
        '本期尚未达标' => 'warning',
        default => 'gray',
    };
@endphp

<div class="member-rescue-eligibility">
    <div class="member-rescue-summary">
        <div>
            <p class="member-rescue-label">核对结果</p>
            <x-filament::badge :color="$statusColor">{{ $result }}</x-filament::badge>
        </div>
        <div>
            <p class="member-rescue-label">预计可领取</p>
            <p @class(['member-rescue-amount', 'member-rescue-approved' => $amount > 0])>{{ $amountLabel }}</p>
        </div>
    </div>

    @if (!empty($assessment['unmet_conditions']))
        <div class="member-rescue-unmet">
            <strong>未达标项目</strong>
            <ul>
                @foreach ($assessment['unmet_conditions'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <dl class="member-rescue-metrics">
        @foreach ([
            'cumulative_deposit_cents' => '累计存款',
            'deposit_cents' => '本期充值',
            'net_loss_cents' => '本期净亏损',
            'required_loss_cents' => '最低所需亏损金额',
        ] as $key => $label)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ isset($assessment[$key]) ? 'R$ '.\App\Points\PointRules::display($assessment[$key], 2) : '—' }}</dd>
            </div>
        @endforeach
        <div>
            <dt>配置的亏损比例</dt>
            <dd>{{ rtrim(rtrim(\App\Points\PointRules::display($assessment['loss_percent_micros']), '0'), '.') }}%</dd>
        </div>
        <div>
            <dt>最高打码倍数</dt>
            <dd>{{ rtrim(rtrim(\App\Points\PointRules::display($assessment['max_wager_tier_micros']), '0'), '.') }} 倍</dd>
        </div>
        <div>
            <dt>本期实际打码倍数</dt>
            <dd>{{ isset($assessment['wager_multiplier']) ? $assessment['wager_multiplier'].' 倍' : '—' }}</dd>
        </div>
        <div>
            <dt>基线时间</dt>
            <dd>
                @if ($baseline)
                    <time datetime="{{ $baseline }}">{{ \Carbon\CarbonImmutable::parse($baseline)->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}</time>
                @else
                    未建立
                @endif
            </dd>
        </div>
    </dl>

    <style>
        .member-rescue-eligibility { display: grid; gap: 0.9rem; }
        .member-rescue-summary {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem 1.5rem;
            padding: 1rem 1.15rem;
            border: 1px solid rgb(128 128 128 / 20%);
            border-radius: 0.75rem;
            background: rgb(128 128 128 / 7%);
        }
        .member-rescue-label,
        .member-rescue-metrics dt { font-size: 0.8125rem; opacity: 0.8; }
        .member-rescue-label { margin-bottom: 0.35rem; }
        .member-rescue-amount {
            font-size: 1.65rem;
            line-height: 1.2;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .member-rescue-approved { color: #047857; }
        .dark .member-rescue-approved { color: #6ee7b7; }
        .member-rescue-unmet {
            padding: 0.8rem 1rem;
            border: 1px solid rgb(202 138 4 / 30%);
            border-radius: 0.75rem;
            background: rgb(234 179 8 / 9%);
            font-size: 0.875rem;
        }
        .member-rescue-unmet ul { margin-top: 0.35rem; padding-inline-start: 1.15rem; list-style: disc; }
        .member-rescue-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            overflow: hidden;
            border: 1px solid rgb(128 128 128 / 20%);
            border-radius: 0.75rem;
        }
        .member-rescue-metrics > div { min-width: 0; padding: 0.8rem 1rem; }
        .member-rescue-metrics > div:nth-child(even) { border-inline-start: 1px solid rgb(128 128 128 / 18%); }
        .member-rescue-metrics > div:nth-child(n+3) { border-block-start: 1px solid rgb(128 128 128 / 18%); }
        .member-rescue-metrics dd {
            margin: 0.25rem 0 0;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }
        @media (max-width: 28rem) {
            .member-rescue-metrics { grid-template-columns: minmax(0, 1fr); }
            .member-rescue-metrics > div:nth-child(even) { border-inline-start: 0; }
            .member-rescue-metrics > div + div { border-block-start: 1px solid rgb(128 128 128 / 18%); }
        }
    </style>
</div>
