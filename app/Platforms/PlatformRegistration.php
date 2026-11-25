<?php

namespace App\Platforms;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBindingAttempt;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Uri;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PlatformRegistration
{
    public function __construct(private readonly BindPlatformAccount $binding) {}

    public function launch(Member $member, int $platformId): string
    {
        return DB::transaction(function () use ($member, $platformId): string {
            $platform = Platform::query()->available()->lockForUpdate()->findOrFail($platformId);
            $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
            abort_if($owner->disabled_at !== null, 403);
            abort_if($owner->session_version !== $member->session_version, 401);

            $url = Uri::of($platform->promotion_url);
            if ($url->scheme() !== 'https') {
                return $platform->promotion_url;
            }

            if (! $platform->bindings()->whereBelongsTo($owner)->exists()) {
                $attempt = $this->attempt($owner, $platform);
                DB::table('platform_registration_intents')->upsert([
                    'member_id' => $owner->id,
                    'platform_id' => $platform->id,
                    'expires_at' => now()->addMinutes(30), // Retained for compatibility with the previous deployment.
                    'platform_binding_attempt_id' => $attempt->id,
                    'revoked_at' => null,
                ], ['member_id', 'platform_id'], ['expires_at', 'platform_binding_attempt_id', 'revoked_at']);
            }

            return (string) $url->withQuery(['bd_id' => $owner->phone, 'platform' => $platform->name]);
        }, 3);
    }

    public function received(string $phone, string $platformName): void
    {
        $owner = Member::query()->where('phone', $phone)->whereNull('disabled_at')->first();
        if ($owner === null) {
            return;
        }

        $platforms = Platform::query()->available()->where('name', $platformName)
            ->whereIn('id', DB::table('platform_registration_intents')->select('platform_id')
                ->where('member_id', $owner->id)->whereNull('revoked_at'))->limit(2)->get();
        if ($platforms->count() !== 1) {
            return;
        }
        $platform = $platforms->first();
        if ($platform->name !== $platformName || $platform->bindings()->whereBelongsTo($owner)->exists()) {
            return;
        }

        RateLimiter::attempt("platform-registration:{$owner->id}:{$platform->id}", 1, function () use ($owner, $platform, $phone): void {
            $attempt = DB::transaction(function () use ($owner, $platform): ?PlatformBindingAttempt {
                $intent = $this->activeIntent($owner, $platform)->lockForUpdate()->first();
                if ($intent === null) {
                    return null;
                }
                $attempt = $this->attempt($owner, $platform, $intent);
                if ($intent->platform_binding_attempt_id === null) {
                    $this->activeIntent($owner, $platform)->update(['platform_binding_attempt_id' => $attempt->id]);
                }
                $attempt->update(['callback_at' => now()]);

                return $attempt;
            }, 3);
            if ($attempt === null) {
                return;
            }
            try {
                $account = $platform->network->queryDriver()->lookup($platform->active_query, $phone);
                if ($account === null) {
                    $attempt->fail(PlatformQueryFailure::label('not_found'));

                    return;
                }
                $this->binding->complete($owner, $platform, $phone, $account, function (Member $current, Platform $currentPlatform) use ($phone, $platform, $attempt): void {
                    abort_if($current->phone !== $phone, 409);
                    abort_if($currentPlatform->name !== $platform->name, 409);
                    abort_unless($this->activeIntent($current, $currentPlatform)->where('platform_binding_attempt_id', $attempt->id)->delete() === 1, 409);
                }, $attempt);
            } catch (PlatformQueryFailure $failure) {
                $attempt->fail(PlatformQueryFailure::label($failure->kind));
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [401, 403, 404, 409], true)) {
                    throw $exception;
                }
                $attempt->fail($exception->getMessage() ?: '会员或平台状态已变更，绑定未完成。');
            }
        }, 10);
    }

    private function activeIntent(Member $member, Platform $platform): Builder
    {
        return DB::table('platform_registration_intents')->where('member_id', $member->id)
            ->where('platform_id', $platform->id)->whereNull('revoked_at');
    }

    private function attempt(Member $owner, Platform $platform, ?object $intent = null): PlatformBindingAttempt
    {
        $intent ??= $this->activeIntent($owner, $platform)->first();
        $record = isset($intent->platform_binding_attempt_id) ? PlatformBindingAttempt::find($intent->platform_binding_attempt_id) : null;

        return $record ?? PlatformBindingAttempt::create([
            'member_id' => $owner->id, 'platform_id' => $platform->id,
            'method' => 'automatic', 'status' => 'processing',
        ]);
    }
}
