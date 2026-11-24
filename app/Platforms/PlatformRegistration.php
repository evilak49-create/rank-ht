<?php

namespace App\Platforms;

use App\Models\Member;
use App\Models\Platform;
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
                DB::table('platform_registration_intents')->upsert([
                    'member_id' => $owner->id,
                    'platform_id' => $platform->id,
                    'session_version' => $owner->session_version,
                    'expires_at' => now()->addMinutes(30),
                ], ['member_id', 'platform_id'], ['session_version', 'expires_at']);
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
                ->where('member_id', $owner->id)->where('session_version', $owner->session_version)
                ->where('expires_at', '>', now()))->limit(2)->get();
        if ($platforms->count() !== 1) {
            return;
        }
        $platform = $platforms->first();
        if ($platform->name !== $platformName || $platform->bindings()->whereBelongsTo($owner)->exists()) {
            return;
        }

        RateLimiter::attempt("platform-registration:{$owner->id}:{$platform->id}", 1, function () use ($owner, $platform, $phone): void {
            try {
                $account = $platform->network->queryDriver()->lookup($platform->active_query, $phone);
                if ($account === null) {
                    return;
                }
                $this->binding->complete($owner, $platform, $phone, $account, function (Member $current, Platform $currentPlatform) use ($phone, $platform): void {
                    abort_if($current->phone !== $phone, 409);
                    abort_if($currentPlatform->name !== $platform->name, 409);
                    abort_unless($this->activeIntent($current, $currentPlatform)->delete() === 1, 409);
                });
            } catch (PlatformQueryFailure) {
                // An unavailable or ambiguous partner account keeps the manual flow available.
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [401, 403, 404, 409], true)) {
                    throw $exception;
                }
            }
        }, 10);
    }

    private function activeIntent(Member $member, Platform $platform): Builder
    {
        return DB::table('platform_registration_intents')->where('member_id', $member->id)
            ->where('platform_id', $platform->id)->where('session_version', $member->session_version)
            ->where('expires_at', '>', now());
    }
}
