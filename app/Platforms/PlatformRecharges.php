<?php

namespace App\Platforms;

use App\Activities\BusinessClock;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PlatformRecharges
{
    public const MAX_PAGE = 1000;

    public function __construct(private readonly BusinessClock $clock) {}

    public function list(Member $member, string $bindingId, int $page): array
    {
        $binding = PlatformBinding::query()->whereBelongsTo($member)->findOrFail($bindingId);
        $platform = Platform::query()->available()->findOrFail($binding->platform_id);
        $configuration = $platform->active_query;
        abort_unless(is_array($configuration), 503, '暂时无法获取充值记录，请稍后重试。');
        $end = CarbonImmutable::now('UTC')->startOfSecond();
        $start = $end->setTimezone($this->clock->current($end))->subMonthNoOverflow()->utc();
        try {
            $result = $platform->network->queryDriver()->rechargePage(
                $configuration, $binding->platform_account_id, $start, $end, $page,
            );
        } catch (PlatformQueryFailure) {
            abort(503, '暂时无法获取充值记录，请稍后重试。');
        }

        $records = collect($result->records)
            ->filter(fn (array $record): bool => $record['recharged_at']->betweenIncluded($start, $end))
            ->keyBy('external_id')->sortByDesc('recharged_at')->values();
        if ($records->isNotEmpty()) {
            DB::transaction(function () use ($binding, $platform, $member, $records): void {
                $current = Platform::query()->available()->lockForUpdate()->findOrFail($platform->id);
                abort_unless($current->network === $platform->network && $current->active_query_revision === $platform->active_query_revision, 409, '平台配置已变更，请重新查询。');
                $owned = PlatformBinding::query()->whereBelongsTo($member)->lockForUpdate()->findOrFail($binding->id);
                abort_unless($owned->platform_id === $platform->id && $owned->platform_account_id === $binding->platform_account_id, 409, '平台账号已变更，请重新查询。');
                $now = now();
                DB::table('platform_recharges')->upsert($records->map(fn (array $record): array => $record + [
                    'platform_binding_id' => $binding->id, 'network' => $platform->network->value,
                    'created_at' => $now, 'updated_at' => $now,
                ])->all(), ['platform_binding_id', 'network', 'external_id'], ['amount_cents', 'recharged_at', 'updated_at']);
            }, 3);
        }

        return [
            'data' => $records->map(fn (array $record): array => [
                'reference' => $record['external_id'], 'amount_cents' => $record['amount_cents'],
                'currency' => 'BRL', 'recharged_at' => $record['recharged_at']->toISOString(),
            ])->all(),
            'current_page' => $page,
            'last_page' => min(self::MAX_PAGE, max(1, intdiv(max(0, $result->total - 1), PlatformRechargePage::SIZE) + 1)),
            'has_more' => $page < self::MAX_PAGE && $page * PlatformRechargePage::SIZE < $result->total,
        ];
    }
}
