<?php

namespace App\Http\Controllers;

use App\Auth\ImageCaptcha;
use App\Auth\MemberPhone;
use App\Auth\MemberVerification;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Platforms\BindPlatformAccount;
use App\Platforms\PlatformParticipation;
use App\Protection\ProtectionClaims;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlatformController extends Controller
{
    private const PUBLIC_FIELDS = ['id', 'name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position'];

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

        return response()->json($query->paginate(20)->appends($filters)->through($this->platformData(...)));
    }

    public function featured(): JsonResponse
    {
        $platforms = Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->whereNotNull('featured_position')
            ->orderBy('featured_position')->limit(10)->get();

        return response()->json(['data' => $platforms->map($this->platformData(...))]);
    }

    public function show(string $platform): JsonResponse
    {
        return response()->json([
            'data' => $this->platformData(Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->findOrFail($platform)),
        ]);
    }

    public function favorites(Request $request): JsonResponse
    {
        $favorites = $request->user()->favoritePlatforms()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()
            ->orderByPivot('created_at', 'desc')->orderByDesc('platforms.id')->paginate(20);

        return response()->json($favorites->through($this->platformData(...)))->header('Cache-Control', 'private, no-store');
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

    private function platformData(Platform $platform): array
    {
        return [...$platform->only(self::PUBLIC_FIELDS), 'review_count' => (int) $platform->review_count,
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
