<?php

namespace App\Shop\Funds;

use App\Models\Platform;
use App\Models\PlatformFundOrder;
use LogicException;

class DisabledFundChannel implements FundChannel
{
    public function isAvailable(Platform $platform): bool
    {
        return false;
    }

    public function send(PlatformFundOrder $order): FundResult
    {
        throw new LogicException('No production platform funds channel is configured.');
    }

    public function lookup(PlatformFundOrder $order): FundResult
    {
        return new FundResult('unknown');
    }
}
