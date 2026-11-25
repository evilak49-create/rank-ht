<?php

namespace App\Protection;

use App\Models\Member;
use App\Models\PlatformBinding;
use App\Models\ProtectionClaim;
use App\Models\ProtectionTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProtectionClaims
{
    public const PERMISSIONS = ['protection.claims.view', 'protection.terms.manage', 'protection.eligibility.manage'];

    public function __construct(private readonly ClaimEvidenceStorage $storage) {}

    public function submit(Member $member, array $data): ProtectionClaim
    {
        $requestId = strtolower($data['request_id']);
        $existing = ProtectionClaim::query()->whereBelongsTo($member)->where('request_id', $requestId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $this->ownedBinding($member, $data['binding_id'])->firstOrFail();
        $disk = $this->storage->uploadDisk();
        $files = [];
        $prefix = 'protection-claims/'.Str::uuid().'/';
        try {
            foreach (array_values($data['evidence']) as $index => $file) {
                $key = $prefix.Str::uuid();
                $mime = $file->getMimeType();
                $files[] = [
                    'disk' => $disk, 'object_key' => $key, 'mime' => $mime,
                    'size_bytes' => $file->getSize(), 'position' => $index + 1,
                ];
                $this->storage->put($disk, $key, $file, $mime);
            }
        } catch (Throwable $exception) {
            $this->storage->cleanUp($disk, array_column($files, 'object_key'));
            Log::warning('Claim evidence upload failed.', ['disk' => $disk, 'error' => $exception::class]);
            abort(503, '申诉凭证上传失败，请重试。');
        }

        try {
            $claim = DB::transaction(function () use ($member, $data, $files, $requestId): ProtectionClaim {
                $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
                abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
                abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');
                $existing = ProtectionClaim::query()->whereBelongsTo($owner)->where('request_id', $requestId)->first();
                if ($existing !== null) {
                    return $existing;
                }

                $binding = $this->ownedBinding($owner, $data['binding_id'])->lockForUpdate()->with('platform')->firstOrFail();
                $termId = ProtectionTerm::currentForPlatform($binding->platform_id)?->id;
                [$units, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
                $cents = (int) $units * 100 + (int) str_pad($fraction, 2, '0');
                $claim = ProtectionClaim::create([
                    'request_id' => $requestId, 'member_id' => $owner->id, 'platform_binding_id' => $binding->id,
                    'platform_id' => $binding->platform_id, 'protection_term_id' => $termId,
                    'platform_name' => $binding->platform->name, 'platform_account_id' => $binding->accountName(),
                    'protection_status' => $binding->currentProtectionStatus(), 'amount_cents' => $cents,
                    'withdrawn_at' => Carbon::parse($data['withdrawn_at'])->utc(), 'description' => $data['description'],
                ]);
                $claim->evidence()->createMany($files);

                return $claim;
            }, 3);
        } catch (Throwable $exception) {
            $this->storage->cleanUp($disk, array_column($files, 'object_key'));

            throw $exception;
        }

        if (! $claim->wasRecentlyCreated) {
            $this->storage->cleanUp($disk, array_column($files, 'object_key'));
        }

        return $claim;
    }

    public static function maskAccount(string $account): string
    {
        return '****'.(mb_strlen($account) > 4 ? mb_substr($account, -4) : '');
    }

    public function resolve(ProtectionClaim $case, User $operator, string $outcome): void
    {
        abort_unless($operator->hasPermission('protection.claims.resolve'), 403);
        abort_unless(in_array($outcome, ['resolved', 'unresolved'], true), 422);
        DB::transaction(function () use ($case, $operator, $outcome): void {
            $updated = ProtectionClaim::query()->whereKey($case->id)->where('case_status', 'pending')
                ->update(['case_status' => $outcome]);
            if ($updated === 0) {
                abort_unless($case->fresh()?->case_status === $outcome, 409, '该申诉已结案。');

                return;
            }
            DB::table('admin_audits')->insert([
                'user_id' => $operator->id, 'member_id' => $case->member_id, 'action' => 'protection.case_resolved',
                'details' => json_encode(['case_id' => $case->id, 'new_status' => $outcome], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function setEligibility(PlatformBinding $binding, User $operator, string $status): void
    {
        abort_unless($operator->hasPermission('protection.eligibility.manage'), 403);
        if (! array_key_exists($status, PlatformBinding::PROTECTION_STATUSES)) {
            throw ValidationException::withMessages(['status' => '请选择有效的保障状态。']);
        }

        DB::transaction(function () use ($binding, $operator, $status): void {
            $current = PlatformBinding::query()->lockForUpdate()->findOrFail($binding->id);
            $previous = $current->protection_status;
            if ($previous === $status) {
                return;
            }

            $current->forceFill(['protection_status' => $status])->save();
            DB::table('admin_audits')->insert([
                'user_id' => $operator->id, 'member_id' => $current->member_id, 'action' => 'protection.eligibility_changed',
                'details' => json_encode(['binding_id' => $current->id, 'old_status' => $previous, 'new_status' => $status], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function ownedBinding(Member $member, int $binding): Builder
    {
        return PlatformBinding::query()->where('member_id', $member->id)->whereKey($binding);
    }
}
