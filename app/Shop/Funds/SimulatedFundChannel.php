<?php

namespace App\Shop\Funds;

use App\Models\Platform;
use App\Models\PlatformFundOrder;

class SimulatedFundChannel implements FundChannel
{
    public function isAvailable(Platform $platform): bool
    {
        return true;
    }

    public function send(PlatformFundOrder $order): FundResult
    {
        return new FundResult('paid', 'simulated-'.$order->order_number);
    }

    public function lookup(PlatformFundOrder $order): FundResult
    {
        return new FundResult('unknown');
    }
}
