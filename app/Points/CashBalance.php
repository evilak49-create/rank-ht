<?php

namespace App\Points;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

class CashBalance
{
    public const MAX_CENTS = 9_007_199_254_740_991;

    // Callers lock the member and post the originating operation in the same transaction.
    public function creditRebate(Member $member, int $amount, int $quoteId): void
    {
        abort_if($amount > self::MAX_CENTS - $member->available_balance_cents - $member->held_balance_cents, 409, '余额超出可入账范围。');
        $this->record($member, $amount, 0, 'rebate', $quoteId);
    }

    public function holdWithdrawal(Member $member, int $amount, int $orderId): void
    {
        abort_unless($amount > 0 && $amount <= $member->available_balance_cents, 409, '可用余额不足。');
        $this->record($member, -$amount, $amount, 'withdrawal', $orderId);
    }

    public function finishWithdrawal(Member $member, int $amount, int $orderId, bool $paid): void
    {
        abort_if($amount > $member->held_balance_cents, 409, '提现冻结金额不一致。');
        if ($paid) {
            $member->forceFill(['held_balance_cents' => $member->held_balance_cents - $amount])->save();
        } else {
            $this->record($member, $amount, -$amount, 'withdrawal_refund', $orderId);
        }
    }

    private function record(Member $member, int $delta, int $hold, string $source, int $sourceId): void
    {
        $member->forceFill([
            'available_balance_cents' => $member->available_balance_cents + $delta,
            'held_balance_cents' => $member->held_balance_cents + $hold,
        ])->save();
        DB::table('cash_balance_entries')->insert([
            'member_id' => $member->id, 'source' => $source, 'source_id' => $sourceId,
            'amount_cents' => $delta, 'balance_after_cents' => $member->available_balance_cents, 'created_at' => now(),
        ]);
    }
}
