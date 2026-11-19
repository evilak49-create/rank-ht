<?php

namespace App\Shop\Funds;

use App\Models\Platform;
use App\Models\PlatformFundOrder;

interface FundChannel
{
    public function isAvailable(Platform $platform): bool;

    public function send(PlatformFundOrder $order): FundResult;

    public function lookup(PlatformFundOrder $order): FundResult;
}
