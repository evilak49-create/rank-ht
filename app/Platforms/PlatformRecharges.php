<?php

namespace App\Platforms;

use App\Activities\BusinessClock;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class PlatformRecharges
{
    public const MAX_PAGE = 1000;

    public function __construct(private readonly BusinessClock $clock) {}

    public function list(Member $member, string $bindingId, int $page, bool $allowUnhealthy = false): array
    {
        $binding = PlatformBinding::query()->whereBelongsTo($member)->findOrFail($bindingId);
        $platform = $this->queryablePlatforms($allowUnhealthy)->findOrFail($binding->platform_id);
        $configuration = $platform->active_query;
        abort_unless(is_array($configuration), 503, '暂时无法获取充值记录，请稍后重试。');
        [$start, $end] = $this->recentPeriod();
        try {
            $result = $platform->network->queryDriver()->rechargePage(
                $configuration, $binding->query_phone, $binding->platform_account_id, $start, $end, $page,
            );
        } catch (PlatformQueryFailure) {
            abort(503, '暂时无法获取充值记录，请稍后重试。');
        }

        $records = collect($result->records)
            ->filter(fn (array $record): bool => $record['recharged_at']->betweenIncluded($start, $end))
            ->keyBy('external_id')->sortByDesc('recharged_at')->values();
        if ($records->isNotEmpty()) {
            DB::transaction(function () use ($binding, $platform, $member, $records, $allowUnhealthy): void {
                $current = $this->queryablePlatforms($allowUnhealthy)->lockForUpdate()->findOrFail($platform->id);
                abort_unless($current->network === $platform->network && $current->active_query_revision === $platform->active_query_revision, 409, '平台配置已变更，请重新查询。');
                $owned = PlatformBinding::query()->whereBelongsTo($member)->lockForUpdate()->findOrFail($binding->id);
                abort_unless($owned->platform_id === $platform->id
                    && $owned->query_phone === $binding->query_phone
                    && $owned->platform_account_id === $binding->platform_account_id, 409, '平台账号已变更，请重新查询。');
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

    public function savedPage(PlatformBinding $binding, int $page): array
    {
        $query = $this->savedQuery($binding)->orderByDesc('recharged_at')->orderByDesc('id');
        $columns = ['external_id', 'amount_cents', 'recharged_at'];
        $records = $query->paginate(PlatformRechargePage::SIZE, $columns, 'page', $page);
        if ($page > $records->lastPage()) {
            $records = $query->paginate(PlatformRechargePage::SIZE, $columns, 'page', $records->lastPage());
        }

        return [
            'data' => $records->getCollection()->map(fn (object $record): array => [
                'reference' => $record->external_id,
                'amount_cents' => (int) $record->amount_cents,
                'currency' => 'BRL',
                'recharged_at' => CarbonImmutable::parse($record->recharged_at, 'UTC')->toISOString(),
            ])->all(),
            'current_page' => $records->currentPage(),
            'last_page' => min(self::MAX_PAGE, $records->lastPage()),
            'has_more' => $records->currentPage() < self::MAX_PAGE && $records->hasMorePages(),
        ];
    }

    public function savedSummary(PlatformBinding $binding): array
    {
        $totals = $this->savedQuery($binding)
            ->selectRaw('COUNT(*) AS record_count, COALESCE(SUM(amount_cents), 0) AS total_cents')->first();

        return ['count' => (int) $totals->record_count, 'total_cents' => (string) $totals->total_cents];
    }

    private function savedQuery(PlatformBinding $binding): QueryBuilder
    {
        return DB::table('platform_recharges')->where('platform_binding_id', $binding->id)
            ->where('network', $binding->platform->network->value)
            ->whereBetween('recharged_at', $this->recentPeriod());
    }

    private function recentPeriod(): array
    {
        $end = CarbonImmutable::now('UTC')->startOfSecond();

        return [$end->setTimezone($this->clock->current($end))->subMonthNoOverflow()->utc(), $end];
    }

    private function queryablePlatforms(bool $allowUnhealthy): Builder
    {
        $query = Platform::query();

        return $allowUnhealthy
            ? $query->where('manually_disabled', false)->whereNotNull('active_query_revision')
            : $query->available();
    }
}
