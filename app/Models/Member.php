<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

#[Fillable(['phone', 'password', 'phone_verified_at', 'promotion_channel_id', 'registration_ip', 'registration_country', 'registration_device_hash'])]
#[Hidden(['password', 'remember_token', 'session_version', 'pix_cpf', 'pix_recipient_name', 'invite_code', 'registration_ip', 'registration_country', 'registration_device_hash'])]
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
            'available_balance_cents' => 'integer',
            'held_balance_cents' => 'integer',
            'pix_cpf' => 'encrypted',
            'pix_recipient_name' => 'encrypted',
        ];
    }

    public function promotionChannel(): BelongsTo
    {
        return $this->belongsTo(PromotionChannel::class);
    }

    public function promotionInvitation(): HasOne
    {
        return $this->hasOne(RedPacketInvite::class, 'invitee_id');
    }

    public function platforms(): BelongsToMany
    {
        return $this->belongsToMany(Platform::class, 'platform_bindings');
    }

    public function platformBindings(): HasMany
    {
        return $this->hasMany(PlatformBinding::class);
    }

    public function favoritePlatforms(): BelongsToMany
    {
        return $this->belongsToMany(Platform::class, 'platform_favorites')->withPivot('created_at');
    }

    public function revokePlatformRegistrationIntents(): void
    {
        DB::table('platform_registration_intents')->where('member_id', $this->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
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
                'remember_token' => null,
            ])->save();

            if ($disabled) {
                $member->revokePlatformRegistrationIntents();
                PlatformFundTask::pauseForMember($member->id);
                PixTask::pauseForMember($member->id);
            } else {
                PixTask::resumeProviderPayoutsForMember($member->id);
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
