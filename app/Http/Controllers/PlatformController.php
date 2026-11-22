<?php

namespace App\Http\Controllers;

use App\Auth\ImageCaptcha;
use App\Auth\MemberPhone;
use App\Auth\MemberVerification;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointRule;
use App\Platforms\BindPlatformAccount;
use App\Platforms\PlatformParticipation;
use App\Platforms\PlatformRecharges;
use App\Points\PlatformRebateTiers;
use App\Points\PointRules;
use App\Protection\ProtectionClaims;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlatformController extends Controller
{
    private const PUBLIC_FIELDS = [
        'id', 'name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position',
        'guarantee_amount_brl',
    ];

    private const INTRODUCTION_FIELDS = [
        'minimum_deposit_brl', 'minimum_withdrawal_brl', 'withdrawal_time', 'support_url',
    ];

    public function index(Request $request): JsonResponse
    {
        return $this->listPlatforms($request);
    }

    public function bindable(Request $request): JsonResponse
    {
        return $this->listPlatforms($request, $request->user()->id);
    }

    private function listPlatforms(Request $request, ?int $memberId = null): JsonResponse
    {
        $filters = $request->validate(['search' => 'nullable|string|max:120']);
        $query = Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->orderBy('name')->orderBy('id');
        if ($memberId !== null) {
            $query->whereDoesntHave('bindings', fn (Builder $bindings) => $bindings->where('member_id', $memberId));
        }
        if (filled($filters['search'] ?? null)) {
            $query->whereLike('name', '%'.$filters['search'].'%');
        }

        $rebate = $this->rebateSummary();

        return response()->json($query->paginate(20)->appends($filters)
            ->through(fn (Platform $record): array => $this->platformData($record, $rebate)));
    }

    public function featured(): JsonResponse
    {
        $platforms = Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->whereNotNull('featured_position')
            ->orderBy('featured_position')->limit(10)->get();

        $rebate = $this->rebateSummary();

        return response()->json(['data' => $platforms->map(fn (Platform $record): array => $this->platformData($record, $rebate))]);
    }

    public function show(string $platform): JsonResponse
    {
        $record = Platform::query()->select([...self::PUBLIC_FIELDS, ...self::INTRODUCTION_FIELDS])
            ->available()->withPublicReviewCount()->findOrFail($platform);
        $rule = PointRule::query()->latest('id')->first();
        $tiers = PlatformRebateTiers::effective($rule);

        return response()->json(['data' => [
            ...$this->platformData($record, PlatformRebateTiers::summary($tiers)),
            ...$record->only(self::INTRODUCTION_FIELDS),
            'point_rule' => $this->pointRuleData($rule, $tiers),
            'rebate_tiers' => PlatformRebateTiers::decimals($tiers),
        ]]);
    }

    public function pointRule(string $platform): JsonResponse
    {
        abort_unless(Platform::query()->available()->whereKey($platform)->exists(), 404);
        $rule = PointRule::query()->latest('id')->first();

        return response()->json(['data' => $this->pointRuleData($rule, PlatformRebateTiers::effective($rule))]);
    }

    private function pointRuleData(?PointRule $rule, array $tiers): ?array
    {
        $first = $tiers[0] ?? null;

        return $rule === null ? null : [
            'points_per_brl' => PointRules::display($rule->points_per_brl_micros),
            'wager_multiplier' => PointRules::display($rule->wager_multiplier_micros),
            'rebate_percent' => PointRules::display($first['percent_micros'] ?? 0),
            'rebate_wager_multiplier' => $first === null ? null : PointRules::display($first['multiplier_micros']),
        ];
    }

    private function rebateSummary(): array
    {
        return PlatformRebateTiers::summary(PlatformRebateTiers::effective(PointRule::query()->latest('id')->first()));
    }

    public function favorites(Request $request): JsonResponse
    {
        $favorites = $request->user()->favoritePlatforms()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()
            ->orderByPivot('created_at', 'desc')->orderByDesc('platforms.id')->paginate(20);
        $rebate = $this->rebateSummary();

        return response()->json($favorites->through(fn (Platform $record): array => $this->platformData($record, $rebate)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function favoriteStatus(Request $request, int $platform): JsonResponse
    {
        $saved = $request->user()->favoritePlatforms()->available()->whereKey($platform)->exists();

        return response()->json(['data' => ['favorite' => $saved]])->header('Cache-Control', 'private, no-store');
    }

    public function favorite(Request $request, int $platform, PlatformParticipation $participation): Response
    {
        $participation->favorite($request->user(), $platform, true);

        return response()->noContent();
    }

    public function unfavorite(Request $request, int $platform, PlatformParticipation $participation): Response
    {
        $participation->favorite($request->user(), $platform, false);

        return response()->noContent();
    }

    public function bindings(Request $request): JsonResponse
    {
        $bindings = $this->ownedBindings($request)->latest('id')->paginate(20);

        return response()->json($bindings->through(fn (PlatformBinding $binding): array => $this->bindingData($binding, includeAccountId: true)));
    }

    public function binding(Request $request, string $binding): JsonResponse
    {
        return response()->json(['data' => $this->bindingData($this->ownedBindings($request)->findOrFail($binding))]);
    }

    public function recharges(Request $request, string $binding, PlatformRecharges $recharges): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:'.PlatformRecharges::MAX_PAGE]]);

        return response()->json($recharges->list($request->user(), $binding, $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    private function ownedBindings(Request $request): Builder
    {
        $memberId = $request->user()->id;
        $confirmedQuotes = fn (Builder $quotes) => $quotes
            ->where('member_id', $memberId)->whereNotNull('confirmed_at');

        return PlatformBinding::query()->where('member_id', $memberId)->with('platform:id,name,logo_url')
            ->withSum(['pointQuotes as redeemed_points' => $confirmedQuotes], 'estimated_points')
            ->withMax(['pointQuotes as last_redeemed_at' => $confirmedQuotes], 'confirmed_at')
            ->withCasts(['last_redeemed_at' => 'datetime']);
    }

    public function sendCode(Request $request, string $platform, BindPlatformAccount $binding): JsonResponse
    {
        $data = $this->validatePhone($request);
        $binding->sendCode($request->user(), Platform::query()->available()->findOrFail($platform), $data['phone']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function bind(Request $request, string $platform, BindPlatformAccount $binding, MemberVerification $verification, ImageCaptcha $captcha): JsonResponse
    {
        $useSms = $verification->smsEnabled();
        $activePlatform = Platform::query()->available()->findOrFail($platform);
        $data = $this->validatePhone($request, $useSms ? ['code' => 'nullable|digits:6'] : ImageCaptcha::RULES);
        if (! $useSms) {
            $captcha->consume($request->session()->getId(), 'platform_binding', $data['challenge_id'], $data['code']);
        }
        $record = $binding->bind(
            $request->user(), $activePlatform, $data['phone'], $useSms ? ($data['code'] ?? null) : null, $useSms,
        );

        $record = $this->ownedBindings($request)->findOrFail($record->id);

        return response()->json(['data' => $this->bindingData($record, includeAccountId: true)], 201);
    }

    private function validatePhone(Request $request, array $verificationRules = []): array
    {
        $request->merge(['phone' => MemberPhone::normalize($request->input('phone'))]);

        return $request->validate([
            'phone' => ['required', MemberPhone::RULE],
            ...$verificationRules,
        ]);
    }

    private function platformData(Platform $platform, array $rebate): array
    {
        return [...$platform->only(self::PUBLIC_FIELDS), ...$rebate,
            'review_count' => (int) $platform->review_count,
            'member_review_count' => (int) $platform->member_review_count];
    }

    private function bindingData(PlatformBinding $binding, bool $includeAccountId = false): array
    {
        return [
            'id' => $binding->id,
            'platform_id' => $binding->platform_id,
            'platform_name' => $binding->platform->name,
            'logo_url' => $binding->platform->logo_url,
            'masked_platform_account' => ProtectionClaims::maskAccount($binding->platform_account_id),
            ...($includeAccountId ? ['platform_account_id' => $binding->platform_account_id] : []),
            'redeemed_points' => (int) $binding->redeemed_points,
            'last_redeemed_at' => $binding->last_redeemed_at?->toISOString(),
            'protection_status' => $binding->protection_status,
            'created_at' => $binding->created_at?->toISOString(),
        ];
    }
}
