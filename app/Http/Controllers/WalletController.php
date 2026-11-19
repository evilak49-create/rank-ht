<?php

namespace App\Http\Controllers;

use App\Models\PointEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->privateResponse(['data' => ['points_balance' => $request->user()->points_balance]]);
    }

    public function entries(Request $request): JsonResponse
    {
        $filters = $request->validate(['direction' => 'nullable|in:credit,debit', 'platform_id' => 'nullable|integer|min:1']);
        $query = $this->owned($request);
        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (isset($filters['platform_id'])) {
            $byPlatform = fn (Builder $related) => $related->where([
                'platform_id' => $filters['platform_id'], 'member_id' => $request->user()->id,
            ]);
            $query->where(fn (Builder $entries) => $entries
                ->where(fn (Builder $deposits) => $deposits->where('source', 'deposit')->whereHas('quote', $byPlatform))
                ->orWhere(fn (Builder $funds) => $funds->whereIn('source', ['platform_funds', 'platform_refund'])
                    ->whereHas('fundOrder', $byPlatform)));
        }

        return $this->privateResponse($query->latest('id')->paginate(20)->withQueryString()->through($this->entryData(...)));
    }

    public function entry(Request $request, string $entry): JsonResponse
    {
        return $this->privateResponse(['data' => $this->entryData($this->owned($request)->findOrFail($entry))]);
    }

    private function owned(Request $request): Builder
    {
        $member = $request->user()->id;

        return PointEntry::query()->where('member_id', $member)->with([
            'quote' => fn ($quote) => $quote
                ->select(['id', 'platform_id', 'confirmed_at', 'expires_at'])->where('member_id', $member),
            'quote.platform:id,name',
            'shopOrder' => fn ($order) => $order
                ->select(['id', 'product_name', 'status'])->where('member_id', $member),
            'fundOrder' => fn ($order) => $order
                ->select(['id', 'platform_id', 'status'])->where('member_id', $member),
            'fundOrder.platform:id,name',
        ]);
    }

    private function entryData(PointEntry $entry): array
    {
        $related = match ($entry->source) {
            'deposit' => $entry->quote,
            'shop_order', 'shop_refund' => $entry->shopOrder,
            'platform_funds', 'platform_refund' => $entry->fundOrder,
            default => null,
        };

        return [
            ...$entry->only(['id', 'source', 'direction', 'amount', 'balance_before', 'balance_after', 'created_at']),
            'source_label' => PointEntry::SOURCES[$entry->source] ?? $entry->source,
            'related' => $related === null ? null : match ($entry->source) {
                'deposit' => [
                    'id' => $related->id, 'type' => 'deposit', 'status' => $related->status(),
                    'platform_id' => $related->platform_id, 'name' => $related->platform?->name,
                ],
                'shop_order', 'shop_refund' => [
                    'id' => $related->id, 'type' => 'shop_order', 'status' => $related->status,
                    'name' => $related->product_name,
                ],
                default => [
                    'id' => $related->id, 'type' => 'platform_funds', 'status' => $related->status,
                    'platform_id' => $related->platform_id, 'name' => $related->platform?->name,
                ],
            },
        ];
    }

    private function privateResponse(mixed $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
