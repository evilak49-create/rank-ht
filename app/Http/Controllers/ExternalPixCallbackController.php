<?php

namespace App\Http\Controllers;

use App\Http\Cloudflare;
use App\Models\PixWithdrawal;
use App\RedPackets\ExternalPixChannel;
use App\RedPackets\PixDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class ExternalPixCallbackController extends Controller
{
    public function __invoke(Request $request, int $channel, ExternalPixChannel $provider, PixDelivery $delivery): Response
    {
        $body = $request->getContent();
        abort_unless($request->isJson() && strlen($body) <= 16_384, 400);
        // Middleware normalizes request fields, while the signature covers the original values.
        $data = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
        abort_unless(is_array($data) && ! array_is_list($data), 400);
        $number = $data['merchantOrderNo'] ?? null;
        abort_unless(is_string($number) && Str::isUuid($number), 400);
        $order = PixWithdrawal::query()->where('payout_channel_id', $channel)->whereNotNull('payout_configuration')
            ->where('order_number', $number)->firstOrFail();
        abort_unless($provider->authenticateCallback($order, $data, $this->sourceIp($request)), 403);
        $result = $provider->result($order, $data);
        abort_if($result === null, 400);
        abort_unless($order->task()->whereNotNull('send_started_at')->exists(), 409);

        $delivery->reconcileProviderCallback($order, $result);

        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    private function sourceIp(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR');
        if (IpUtils::checkIp($remote, Cloudflare::PROXIES)) {
            $client = $request->header('CF-Connecting-IP');
            if (filter_var($client, FILTER_VALIDATE_IP) !== false) {
                return $client;
            }
        }

        return $remote;
    }
}
