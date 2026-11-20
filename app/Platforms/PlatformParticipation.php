<?php

namespace App\Platforms;

use App\Auth\MemberPhone;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PlatformParticipation
{
    public function favorite(Member $member, int $platform, bool $enabled): void
    {
        DB::transaction(function () use ($member, $platform, $enabled): void {
            $account = $this->lockMember($member);
            $keys = ['member_id' => $account->id, 'platform_id' => $platform];
            if ($enabled) {
                Platform::query()->available()->lockForUpdate()->findOrFail($platform);
                DB::table('platform_favorites')->insertOrIgnore($keys + ['created_at' => now()]);
            } else {
                DB::table('platform_favorites')->where($keys)->delete();
            }
        }, 3);
    }

    public function review(Member $member, int $platform, array $data): PlatformReview
    {
        return DB::transaction(function () use ($member, $platform, $data): PlatformReview {
            $account = $this->lockMember($member);
            Platform::query()->available()->lockForUpdate()->findOrFail($platform);
            $request = strtolower($data['request_id']);
            $existing = PlatformReview::query()->whereBelongsTo($account)->where('request_id', $request)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->platform_id === $platform, 409, '该提交编号已用于其他平台。');

                return $existing;
            }

            $binding = PlatformBinding::query()->where(['member_id' => $account->id, 'platform_id' => $platform])
                ->lockForUpdate()->first();
            abort_if($binding === null, 403, '绑定该平台后可发表评价。');

            return PlatformReview::create([
                'platform_id' => $platform, 'member_id' => $account->id, 'request_id' => $request,
                'display_name' => MemberPhone::mask($account->phone) ?? '会员',
                'rating' => $data['rating'], 'body' => $data['body'], 'status' => 'pending',
            ]);
        }, 3);
    }

    public function moderate(PlatformReview $review, bool $approved, User $operator, ?string $reason = null): void
    {
        abort_unless($operator->hasPermission('platforms.view-reviews') && $operator->hasPermission('platforms.moderate-reviews'), 403);
        if (! $approved) {
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:300'])->validate();
        }
        DB::transaction(function () use ($review, $approved, $operator, $reason): void {
            $record = PlatformReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($record->status !== 'pending') {
                return;
            }

            $time = now();
            $record->forceFill([
                'status' => $approved ? 'approved' : 'rejected', 'reviewer_id' => $operator->id,
                'reviewed_at' => $time, 'published_at' => $approved ? $time : null,
                'review_reason' => $approved ? null : $reason,
            ])->save();
        }, 3);
    }

    private function lockMember(Member $member): Member
    {
        $account = Member::query()->lockForUpdate()->findOrFail($member->id);
        abort_if($account->disabled_at !== null, 403, '会员账号已禁用。');
        abort_if($account->session_version !== $member->session_version, 401, '会员登录会话已失效。');

        return $account;
    }
}
