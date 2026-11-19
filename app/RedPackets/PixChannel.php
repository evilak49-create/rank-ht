<?php

namespace App\RedPackets;

use App\Models\PixWithdrawal;

interface PixChannel
{
    public function isAvailable(): bool;

    public function send(PixWithdrawal $order): PixResult;

    public function lookup(PixWithdrawal $order): PixResult;
}
