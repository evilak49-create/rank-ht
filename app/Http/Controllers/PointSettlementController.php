<?php

namespace App\Http\Controllers;

use App\Models\PointQuote;
use App\Points\PointSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PointSettlementController extends Controller
{
    public function quote(Request $request, string $binding, PointSettlement $settlement): JsonResponse
    {
        return response()->json(['data' => $this->data($settlement->quote($request->user(), $binding))], 201);
    }

    public function confirm(Request $request, string $quote, PointSettlement $settlement): JsonResponse
    {
        return response()->json(['data' => $this->data($settlement->confirm($request->user(), $quote))]);
    }

    private function data(PointQuote $quote): array
    {
        return [
            'id' => $quote->id, 'rule_version' => $quote->point_rule_id, 'estimated_points' => $quote->estimated_points,
            'eligibility' => $quote->eligibility, 'status' => $quote->status(),
            'expires_at' => $quote->expires_at->toISOString(), 'confirmed_at' => $quote->confirmed_at?->toISOString(),
            'point_entry_id' => $quote->point_entry_id,
            ...Arr::except($quote->snapshot, ['remainder_units', 'observed_deposit_cents', 'observed_valid_bet_cents']),
        ];
    }
}
