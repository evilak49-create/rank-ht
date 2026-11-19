<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'user_id', 'operation_key', 'direction', 'amount', 'balance_before', 'balance_after', 'reason', 'source', 'source_id'])]
class PointEntry extends Model
{
    public const SOURCES = [
        'manual' => '人工调整', 'deposit' => '存款换积分', 'shop_order' => '商品兑换',
        'shop_refund' => '商品退回', 'checkin' => '签到奖励',
        'platform_funds' => '平台资金兑换', 'platform_refund' => '平台资金退款',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_before' => 'integer', 'balance_after' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PointQuote::class, 'source_id');
    }

    public function shopOrder(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'source_id');
    }

    public function fundOrder(): BelongsTo
    {
        return $this->belongsTo(PlatformFundOrder::class, 'source_id');
    }
}
