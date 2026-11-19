<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

#[Fillable(['phone', 'password', 'phone_verified_at'])]
#[Hidden(['password', 'session_version', 'pix_cpf', 'invite_code'])]
class Member extends Authenticatable
{
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'session_version' => 'integer',
            'points_balance' => 'integer',
            'pix_cpf' => 'encrypted',
        ];
    }

    public function platforms(): BelongsToMany
    {
        return $this->belongsToMany(Platform::class, 'platform_bindings');
    }

    public function changeDisabledStatus(bool $disabled, ?User $operator = null): void
    {
        DB::transaction(function () use ($disabled, $operator): void {
            $member = self::query()->lockForUpdate()->findOrFail($this->getKey());
            if (($member->disabled_at !== null) === $disabled) {
                return;
            }

            $member->forceFill([
                'disabled_at' => $disabled ? now() : null,
                'session_version' => $member->session_version + 1,
            ])->save();

            if ($disabled) {
                PlatformFundTask::pauseForMember($member->id);
                PixTask::pauseForMember($member->id);
            }

            if ($operator !== null) {
                DB::table('admin_audits')->insert([
                    'user_id' => $operator->id,
                    'member_id' => $member->id,
                    'action' => $disabled ? 'member.disabled' : 'member.restored',
                    'created_at' => now(),
                ]);
            }
        });
    }
}
