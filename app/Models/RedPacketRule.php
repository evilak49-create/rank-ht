<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['threshold_cents', 'page_copy', 'ratio_bps', 'user_id'])]
class RedPacketRule extends Model
{
    public const DEFAULT_RATIOS = ['initial_min' => 8500, 'initial_max' => 9500, 'invite_min' => 5000, 'invite_max' => 7000];

    public function ratios(): array
    {
        return ($this->ratio_bps ?? []) + self::DEFAULT_RATIOS;
    }

    protected function casts(): array
    {
        return ['threshold_cents' => 'integer', 'page_copy' => 'array', 'ratio_bps' => 'array'];
    }
}
