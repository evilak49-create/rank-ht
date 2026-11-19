<?php

namespace App\Points;

use App\Models\Member;
use App\Models\PointEntry;
use Illuminate\Validation\ValidationException;

class PointWallet
{
    // All callers acquire the member row lock and commit the business record with its ledger entry.
    public function record(Member $lockedMember, int $delta, array $details): PointEntry
    {
        $before = $lockedMember->points_balance;
        if ($delta < 0 && -$delta > $before) {
            throw ValidationException::withMessages(['amount' => '可用积分不足。']);
        }
        if ($delta > PHP_INT_MAX - $before) {
            throw ValidationException::withMessages(['amount' => '积分余额超出支持范围。']);
        }
        $after = $before + $delta;
        $lockedMember->forceFill(['points_balance' => $after])->save();

        return PointEntry::create([
            ...$details, 'member_id' => $lockedMember->id,
            'direction' => $delta < 0 ? 'debit' : 'credit', 'amount' => abs($delta),
            'balance_before' => $before, 'balance_after' => $after,
        ]);
    }
}
