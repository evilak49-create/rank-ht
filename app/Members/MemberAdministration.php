<?php

namespace App\Members;

use App\Auth\MemberPassword;
use App\Auth\MemberPhone;
use App\Models\Member;
use App\Models\PointEntry;
use App\Models\User;
use App\Points\PointWallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MemberAdministration
{
    public const PERMISSIONS = ['members.change-phone', 'members.reset-password', 'members.adjust-points', 'points.view'];

    public function __construct(private readonly PointWallet $wallet) {}

    public function changePhone(Member $member, string $newPhone, User $operator): void
    {
        abort_unless($operator->hasPermission('members.change-phone'), 403);

        $phone = MemberPhone::normalize($newPhone);
        Validator::make(['phone' => $phone], ['phone' => ['required', MemberPhone::RULE]])->validate();

        try {
            DB::transaction(function () use ($member, $phone, $operator): void {
                $current = Member::query()->lockForUpdate()->findOrFail($member->id);
                if ($current->phone === $phone) {
                    return;
                }

                $previous = $current->phone;
                $current->forceFill([
                    'phone' => $phone, 'phone_verified_at' => null, 'session_version' => $current->session_version + 1,
                ])->save();
                $this->audit($current, $operator, 'member.phone_changed', ['previous_phone' => $previous, 'new_phone' => $phone]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => '该手机号已被其他会员使用。']);
        }
    }

    public function resetPassword(Member $member, array $data, User $operator): void
    {
        abort_unless($operator->hasPermission('members.reset-password'), 403);
        $data = Validator::make($data, ['password' => MemberPassword::RULE.'|confirmed'])->validate();
        MemberPassword::validateBytes($data['password']);

        DB::transaction(function () use ($member, $data, $operator): void {
            $current = Member::query()->lockForUpdate()->findOrFail($member->id);
            $current->forceFill(['password' => $data['password'], 'session_version' => $current->session_version + 1])->save();
            $this->audit($current, $operator, 'member.password_reset');
        }, 3);
    }

    public function adjustPoints(Member $member, array $data, User $operator): PointEntry
    {
        abort_unless($operator->hasPermission('members.adjust-points'), 403);
        $data = Validator::make($data, [
            'direction' => 'required|in:credit,debit',
            'amount' => 'required|integer|min:1|max:2147483647',
            'reason' => 'required|string|max:300',
            'operation_key' => 'required|uuid',
        ])->validate();
        $data['operation_key'] = strtolower($data['operation_key']);
        $amount = (int) $data['amount'];

        return DB::transaction(function () use ($member, $data, $operator, $amount): PointEntry {
            $current = Member::query()->lockForUpdate()->findOrFail($member->id);
            $entry = PointEntry::query()->where('member_id', $current->id)->where('operation_key', $data['operation_key'])->first();
            if ($entry !== null) {
                abort_unless((int) $entry->user_id === $operator->id && $entry->direction === $data['direction']
                    && $entry->amount === $amount && $entry->reason === $data['reason'], 409, '该操作编号已使用。');

                return $entry;
            }

            $entry = $this->wallet->record($current, $data['direction'] === 'credit' ? $amount : -$amount, [
                'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => $operator->id,
            ]);
            $this->audit($current, $operator, 'member.points_adjusted', ['point_entry_id' => $entry->id]);

            return $entry;
        }, 3);
    }

    private function audit(Member $member, User $operator, string $action, ?array $details = null): void
    {
        DB::table('admin_audits')->insert([
            'user_id' => $operator->id, 'member_id' => $member->id, 'action' => $action,
            'details' => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
