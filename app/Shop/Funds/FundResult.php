<?php

namespace App\Shop\Funds;

final readonly class FundResult
{
    public function __construct(public string $status, public ?string $reference = null) {}
}
