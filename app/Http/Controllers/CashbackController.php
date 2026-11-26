<?php

namespace App\Http\Controllers;

use App\Models\PlatformBinding;
use App\Models\PointQuote;
use App\Points\CashbackSchedule;
use App\Points\PointSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CashbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bindings = $this->ownedBindings($request)->latest('id')->paginate(10);

        return $this->response([
            ...$bindings->through($this->platformData(...))->toArray(),
            ...CashbackSchedule::information(),
        ]);
    }

    public function show(Request $request, string $binding, PointSettlement $settlement): JsonResponse
    {
        $record = $this->ownedBindings($request)->findOrFail($binding);

        return $this->response([
            'data' => [...$this->platformData($record), ...Arr::only($settlement->preview($record), [
                'current_cents', 'estimated_cents', 'rebate_percent', 'max_rebate_percent', 'observed_at',
                'observed_deposit_cents', 'observed_valid_bet_cents', 'deposit_cents', 'valid_bet_cents',
                'matched_rebate_wager_multiplier',
                'estimated_wager_multiplier', 'estimated_rebate_percent',
            ])],
            ...CashbackSchedule::information(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $records = PointQuote::query()->whereBelongsTo($request->user())
            ->whereNotNull('dispatch_day')->with('platform:id,name')
            ->orderByDesc('dispatch_day')->latest('id')->paginate(20);

        return $this->response($records->through(fn (PointQuote $record): array => [
            'id' => $record->id,
            'platform_name' => $record->platform->name,
            'binding_id' => $record->platform_binding_id,
            'result' => $record->confirmed_at ? 'credited' : $record->eligibility,
            'amount_cents' => $record->confirmed_at ? $record->rebate_cents : 0,
            'settled_at' => ($record->confirmed_at ?? $record->created_at)->toISOString(),
        ])->toArray());
    }

    private function ownedBindings(Request $request): Builder
    {
        return PlatformBinding::query()->where('member_id', $request->user()->id)->with('platform:id,name,logo_url');
    }

    private function platformData(PlatformBinding $binding): array
    {
        return [
            'binding_id' => $binding->id,
            'platform_id' => $binding->platform_id,
            'platform_name' => $binding->platform->name,
            'logo_url' => $binding->platform->logo_url,
        ];
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
