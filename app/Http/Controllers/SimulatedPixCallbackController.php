<?php

namespace App\Http\Controllers;

use App\Models\PixWithdrawal;
use App\RedPackets\PixDelivery;
use App\RedPackets\PixResult;
use App\RedPackets\SimulatedPixChannel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SimulatedPixCallbackController extends Controller
{
    public function __invoke(Request $request, SimulatedPixChannel $channel, PixDelivery $delivery): Response
    {
        $key = config('services.pix.simulator_callback_key');
        abort_unless($channel->isAvailable() && is_string($key) && strlen($key) >= 32, 404);
        $time = $request->header('X-Pix-Timestamp', '');
        $signature = $request->header('X-Pix-Signature', '');
        abort_unless(ctype_digit($time) && strlen($time) <= 12 && abs(now()->timestamp - (int) $time) <= 300
            && hash_equals(hash_hmac('sha256', $time.'.'.$request->getContent(), $key), $signature), 403);

        $data = validator($request->json()->all(), [
            'order_number' => ['required', 'uuid'],
            'status' => ['required', 'in:paid,failed'],
            'reference' => ['required_if:status,paid', 'nullable', 'string', 'max:190'],
        ])->validate();

        $order = PixWithdrawal::query()->where('order_number', $data['order_number'])->firstOrFail();
        $delivery->reconcile($order, new PixResult($data['status'], $data['reference'] ?? null));

        return response()->noContent();
    }
}
