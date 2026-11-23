<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CashWalletEntryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate(['source' => ['nullable', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]*$/D']]);
        $entries = DB::table('cash_balance_entries as entries')
            ->where('entries.member_id', $request->user()->id)
            ->when(isset($filters['source']), fn ($query) => $query->where('entries.source', $filters['source']))
            ->leftJoin('point_quotes as quotes', fn (JoinClause $join) => $join
                ->on('quotes.id', 'entries.source_id')->on('quotes.member_id', 'entries.member_id')
                ->where('entries.source', 'rebate'))
            ->leftJoin('platform_fund_orders as orders', fn (JoinClause $join) => $join
                ->on('orders.id', 'entries.source_id')->on('orders.member_id', 'entries.member_id')
                ->whereIn('entries.source', ['platform_payment', 'platform_refund']))
            ->leftJoin('platforms as rebate_platforms', 'rebate_platforms.id', '=', 'quotes.platform_id')
            ->leftJoin('platforms as fund_platforms', 'fund_platforms.id', '=', 'orders.platform_id')
            ->latest('entries.created_at')->orderByDesc('entries.id')->paginate(20, [
                'entries.id', 'entries.source', 'entries.source_id', 'entries.amount_cents', 'entries.balance_after_cents',
                'entries.created_at', 'rebate_platforms.name as rebate_platform', 'fund_platforms.name as fund_platform',
            ])->withQueryString();

        $claimIds = $entries->getCollection()->where('source', 'rescue_fund')->pluck('source_id')->all();
        $claimPlatforms = $claimIds === [] ? collect() : DB::table('rescue_fund_claims')
            ->where('member_id', $request->user()->id)->whereIn('id', $claimIds)->pluck('platform_name', 'id');

        return response()->json($entries->through(fn (object $entry): array => [
            'id' => (int) $entry->id,
            'source' => $entry->source,
            'amount_cents' => (int) $entry->amount_cents,
            'balance_after_cents' => (int) $entry->balance_after_cents,
            'created_at' => Carbon::parse($entry->created_at)->toISOString(),
            'platform_name' => match ($entry->source) {
                'rebate' => $entry->rebate_platform,
                'rescue_fund' => $claimPlatforms->get($entry->source_id),
                'platform_payment', 'platform_refund' => $entry->fund_platform,
                default => null,
            },
        ]));
    }
}
