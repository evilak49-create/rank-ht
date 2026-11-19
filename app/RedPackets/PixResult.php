<?php

namespace App\RedPackets;

final readonly class PixResult
{
    public function __construct(public string $status, public ?string $reference = null) {}
}
