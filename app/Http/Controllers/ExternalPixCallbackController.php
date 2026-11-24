<?php

namespace App\Http\Controllers;

use App\Models\PixWithdrawal;
use App\RedPackets\ExternalPixChannel;
use App\RedPackets\PixDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class ExternalPixCallbackController extends Controller
{
    // https://www.cloudflare.com/ips/ — scope proxy header trust to this callback.
    private const CLOUDFLARE_PROXIES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

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

        if ($result->status !== 'unknown') {
            $delivery->reconcile($order, $result);
        }

        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    private function sourceIp(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR');
        if (IpUtils::checkIp($remote, self::CLOUDFLARE_PROXIES)) {
            $client = $request->header('CF-Connecting-IP');
            if (filter_var($client, FILTER_VALIDATE_IP) !== false) {
                return $client;
            }
        }

        return $remote;
    }
}
