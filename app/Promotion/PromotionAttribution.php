<?php

namespace App\Promotion;

use App\Activities\BusinessClock;
use App\Models\PromotionChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionAttribution
{
    private const COOKIE = 'promotion_visit';

    private const DAYS = 30;

    public function __construct(private readonly BusinessClock $clock) {}

    public function visit(Request $request, PromotionChannel $channel): RedirectResponse
    {
        $instant = now()->utc();
        $date = $instant->copy()->setTimezone($this->clock->current($instant))->toDateString();
        [$visitor] = $this->read($request);
        $visitor = Str::isUuid($visitor) ? $visitor : (string) Str::uuid();

        DB::table('promotion_visits')->insertOrIgnore([
            'promotion_channel_id' => $channel->id,
            'business_date' => $date,
            'visitor_id' => $visitor,
        ]);

        return redirect('/')->header('Cache-Control', 'private, no-store')->withCookie(cookie(
            self::COOKIE,
            implode(':', [$visitor, $channel->id, $instant->timestamp]),
            self::DAYS * 1440,
            secure: $request->isSecure() || str_starts_with(config('app.url'), 'https://'),
            sameSite: 'lax',
        ));
    }

    public function channelId(Request $request): ?int
    {
        [, $channel, $clicked] = $this->read($request);
        if (! ctype_digit($channel) || ! ctype_digit($clicked) || (int) $clicked <= now()->subDays(self::DAYS)->timestamp) {
            return null;
        }

        return PromotionChannel::query()->whereKey((int) $channel)->value('id');
    }

    private function read(Request $request): array
    {
        return array_pad(explode(':', (string) $request->cookie(self::COOKIE), 3), 3, '');
    }
}
