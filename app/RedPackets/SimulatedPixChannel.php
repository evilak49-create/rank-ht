<?php

namespace App\RedPackets;

use App\Models\PixWithdrawal;

class SimulatedPixChannel implements PixChannel
{
    public function isAvailable(): bool
    {
        return app()->environment(['local', 'testing']) && config('services.pix.driver') === 'simulator';
    }

    public function send(PixWithdrawal $order): PixResult
    {
        return $this->lookup($order);
    }

    public function lookup(PixWithdrawal $order): PixResult
    {
        return $this->isAvailable()
            ? new PixResult('paid', 'simulated-'.$order->order_number)
            : new PixResult('unknown');
    }
}
