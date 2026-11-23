<?php

namespace App\Http\Controllers;

use App\Points\RescueFund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RescueFundController extends Controller
{
    public function index(Request $request, RescueFund $rescue): JsonResponse
    {
        return response()->json($rescue->eligible($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function claim(Request $request, string $binding, RescueFund $rescue): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $claim = $rescue->claim($request->user(), $binding, strtolower($data['request_id']));

        return response()->json(['data' => [
            'id' => $claim->id, 'amount_cents' => $claim->amount_cents, 'credited_at' => $claim->credited_at->toISOString(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
